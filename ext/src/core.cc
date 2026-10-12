// The lifetime and the message loop of CEF in a PHP process.
//
// The PHP process is the browser process of CEF, and PHP's main thread is the CEF UI thread: CEF runs with
// the external message pump and the script calls CefDoMessageLoopWork() (Cef::run does it in a loop). CEF asks
// for work from any thread (OnScheduleMessagePumpWork), so that request is answered here with atomics and an
// eventfd only and never touches PHP (cautions.md, rules 1 and 2).
#include <dlfcn.h>
#include <poll.h>
#include <sys/eventfd.h>
#include <unistd.h>

#include <limits.h>

#include <algorithm>
#include <chrono>
#include <cstdlib>
#include <map>

#include "core.h"
#include "include/cef_version.h"

namespace {

// -- the requests for work -----------------------------------------------------------------------------------

std::atomic<int64_t> g_deadline_ms{-1};  // when CEF wants to run (monotonic milliseconds), -1 for no request
std::atomic<bool> g_work_now{false};
// True when the script asked for CEF's external message pump (Settings::externalMessagePump). CEF then runs only the work
// it announced (OnScheduleMessagePumpWork) and does not read the X11 socket of the windows it owns itself, so a Views
// window or a browser window made by CEF gets no mouse and keyboard input. The default is the polling loop CEF
// recommends: Cef::doMessageLoopWork() at least every few milliseconds (verified-findings F19).
std::atomic<bool> g_external_pump{false};
// True once CefShutdown() has run: CEF cannot be initialized again in the same process (it crashes if tried).
std::atomic<bool> g_was_shut_down{false};
constexpr int kPollMs = 5;
int g_wake_fd = -1;
bool g_quit = false;
bool g_context_ready = false;

int64_t now_ms() {
  return std::chrono::duration_cast<std::chrono::milliseconds>(std::chrono::steady_clock::now().time_since_epoch())
      .count();
}

void wake() {
  if (g_wake_fd >= 0) {
    uint64_t one = 1;
    ssize_t ignored = write(g_wake_fd, &one, sizeof(one));
    (void)ignored;
  }
}

// -- the application object CEF gets ----------------------------------------------------------------------------

std::vector<std::pair<std::string, std::string>> g_switches;
std::vector<std::pair<std::string, int>> g_schemes;  // custom schemes: name and cef_scheme_options_t

// The schemes go to the other processes on their command line ("name:options,name:options"), where
// cefloomsubprocess registers them again: every process has to know a custom scheme.
std::string scheme_list() {
  std::string list;
  for (const auto& scheme : g_schemes) {
    if (!list.empty()) list += ",";
    list += scheme.first + ":" + std::to_string(scheme.second);
  }
  return list;
}

class NativeBrowserProcessHandler : public Fwd_CefBrowserProcessHandler {
 public:
  void OnScheduleMessagePumpWork(int64_t delay_ms) override { cl_core_schedule_pump_work(delay_ms); }
  void OnContextInitialized() override {
    g_context_ready = true;
    Fwd_CefBrowserProcessHandler::OnContextInitialized();
  }
  void OnBeforeChildProcessLaunch(CefRefPtr<CefCommandLine> command_line) override {
    if (!g_schemes.empty()) {
      command_line->AppendSwitchWithValue("cefloom-schemes", scheme_list());
    }
    Fwd_CefBrowserProcessHandler::OnBeforeChildProcessLaunch(command_line);
  }
  void set_forward(CefRefPtr<CefBrowserProcessHandler> next) { forward_ = next; }

 private:
  IMPLEMENT_REFCOUNTING(NativeBrowserProcessHandler);
};

class NativeApp : public Fwd_CefApp {
 public:
  explicit NativeApp(CefRefPtr<CefApp> user) : handler_(new NativeBrowserProcessHandler()) { forward_ = user; }

  void OnBeforeCommandLineProcessing(const CefString& process_type, CefRefPtr<CefCommandLine> command_line) override {
    if (process_type.empty()) {
      for (const auto& entry : g_switches) {
        if (entry.second.empty()) {
          command_line->AppendSwitch(entry.first);
        } else {
          command_line->AppendSwitchWithValue(entry.first, entry.second);
        }
      }
    }
    Fwd_CefApp::OnBeforeCommandLineProcessing(process_type, command_line);
  }

  void OnRegisterCustomSchemes(CefRawPtr<CefSchemeRegistrar> registrar) override {
    for (const auto& scheme : g_schemes) {
      registrar->AddCustomScheme(scheme.first, scheme.second);
    }
  }

  CefRefPtr<CefBrowserProcessHandler> GetBrowserProcessHandler() override {
    handler_->set_forward(forward_ ? forward_->GetBrowserProcessHandler() : nullptr);
    return handler_;
  }

 private:
  CefRefPtr<NativeBrowserProcessHandler> handler_;
  IMPLEMENT_REFCOUNTING(NativeApp);
};

CefRefPtr<NativeApp> g_app;

// -- the browsers -------------------------------------------------------------------------------------------------

std::map<int, CefRefPtr<CefBrowser>> g_browsers;
bool g_browser_seen = false;

// -- paths -------------------------------------------------------------------------------------------------------

// The directory that holds the module, libcef.so and the CEF resources (the layout of a cefloom install).
std::string absolute_dir(const std::string& path) {
  char resolved[PATH_MAX];
  return realpath(path.c_str(), resolved) ? std::string(resolved) : path;
}

std::string module_dir() {
  Dl_info info;
  if (dladdr(reinterpret_cast<void*>(&cl_core_register), &info) && info.dli_fname) {
    // dli_fname is the path PHP loaded the module by: it can be relative to the working directory.
    char resolved[PATH_MAX];
    std::string path = realpath(info.dli_fname, resolved) ? std::string(resolved) : std::string(info.dli_fname);
    size_t slash = path.find_last_of('/');
    if (slash != std::string::npos) {
      return path.substr(0, slash);
    }
  }
  return absolute_dir(".");
}

// Where a runtime installed for the user lives: <data home>/cefloom/runtime/<CEF version>. The version is part of the
// path because the module only works with the runtime it was built against.
std::string user_runtime_dir() {
  const char* xdg = getenv("XDG_DATA_HOME");
  const char* user_home = getenv("HOME");
  std::string base = xdg && *xdg ? xdg : (user_home && *user_home ? std::string(user_home) + "/.local/share" : "/tmp");
  return base + "/cefloom/runtime/" + std::to_string(CEF_VERSION_MAJOR) + "." + std::to_string(CEF_VERSION_MINOR) + "." +
         std::to_string(CEF_VERSION_PATCH);
}

bool has_runtime(const std::string& dir) { return access((dir + "/libcef.so").c_str(), R_OK) == 0; }

// The directory of the CEF runtime (libcef.so, the resources, cefloomsubprocess): CEFLOOM_HOME if set, else the
// directory of the module if the runtime is there (a build directory), else the user's runtime directory.
std::string home_dir() {
  const char* env = getenv("CEFLOOM_HOME");
  if (env && *env) {
    return absolute_dir(env);
  }
  const std::string beside = module_dir();
  if (has_runtime(beside)) {
    return beside;
  }
  const std::string user = user_runtime_dir();
  if (has_runtime(user)) {
    return user;
  }
  return beside;
}

#ifdef CEFLOOM_LAZY_LIBCEF
void* g_libcef = nullptr;
#endif

}  // namespace

#ifdef CEFLOOM_LAZY_LIBCEF
void cl_lazy_set_libcef(void* handle);  // lazy_stubs.cc
#endif

bool cl_core_load_cef(std::string* error) {
#ifdef CEFLOOM_LAZY_LIBCEF
  if (g_libcef) {
    return true;
  }
  const std::string dir = home_dir();
  const std::string path = dir + "/libcef.so";
  if (access(path.c_str(), R_OK) != 0) {
    if (error) {
      const char* env = getenv("CEFLOOM_HOME");
      *error = "The CEF runtime was not found: " + path + " does not exist. ";
      if (!(env && *env)) {
        *error += "Looked next to the module (" + module_dir() + ") and in " + user_runtime_dir() + ". ";
      }
      *error += "Install the runtime with the cefloom-runtime tool, or set CEFLOOM_HOME to the directory that holds it.";
    }
    return false;
  }
  void* handle = dlopen(path.c_str(), RTLD_NOW | RTLD_GLOBAL);
  if (!handle) {
    if (error) {
      const char* reason = dlerror();
      *error = "The CEF runtime could not be loaded: " + std::string(reason ? reason : path);
    }
    return false;
  }
  g_libcef = handle;
  cl_lazy_set_libcef(handle);
  // Libcef aborts the process when an object is made before the API version is configured.
  cef_api_hash(CEF_API_VERSION, 0);
#endif
  return true;
}

namespace {

void set_if_empty(cef_string_t* field, const std::string& value) {
  if (field->length == 0) {
    CefString(field).FromString(value);
  }
}

void apply_defaults(CefSettings& s) {
  const std::string home = home_dir();
  s.multi_threaded_message_loop = false;   // PHP owns the loop; external_message_pump stays what the script chose
  s.no_sandbox = true;                  // the sandbox needs chrome-sandbox set up; see cautions.md
  set_if_empty(&s.browser_subprocess_path, home + "/cefloomsubprocess");
  set_if_empty(&s.resources_dir_path, home);
  set_if_empty(&s.locales_dir_path, home + "/locales");
  if (s.root_cache_path.length == 0) {
    const char* xdg = getenv("XDG_CACHE_HOME");
    const char* user_home = getenv("HOME");
    std::string base = xdg && *xdg ? xdg : (user_home ? std::string(user_home) + "/.cache" : "/tmp");
    CefString(&s.root_cache_path).FromString(base + "/cefloom");
  }
}

// -- one step of the loop -----------------------------------------------------------------------------------

void pump_once() {
  cl::drain_releases();
  g_work_now = false;
  int64_t deadline = g_deadline_ms.load();
  if (deadline >= 0 && deadline <= now_ms()) {
    g_deadline_ms = -1;
  }
  cl::g_pump_since_ms = cl::steady_ms();
  CefDoMessageLoopWork();
  cl::g_pump_since_ms = 0;
  cl::drain_releases();
}

// Sleep until CEF asks for work, a wake up arrives, or |max_ms| have passed.
void wait_for_work(int max_ms) {
  if (g_work_now.load()) {
    return;
  }
  if (!g_external_pump.load()) {
    max_ms = std::min(max_ms, kPollMs);  // CEF announces nothing in the polling mode: come back soon
  }
  int64_t deadline = g_deadline_ms.load();
  if (deadline >= 0) {
    int64_t left = deadline - now_ms();
    if (left <= 0) {
      return;
    }
    max_ms = static_cast<int>(std::min<int64_t>(max_ms, left));
  }
  if (g_wake_fd < 0) {
    usleep(static_cast<useconds_t>(max_ms) * 1000);
    return;
  }
  struct pollfd fd = {g_wake_fd, POLLIN, 0};
  if (poll(&fd, 1, max_ms) > 0) {
    uint64_t value;
    ssize_t ignored = read(g_wake_fd, &value, sizeof(value));
    (void)ignored;
  }
}

bool do_shutdown(bool from_script) {
  if (!cl::g_cef_running) {
    return true;
  }
  // Close the browsers and let CEF finish with them.
  for (auto& browser : cl_core_browsers()) {
    CefRefPtr<CefBrowserHost> host = browser->GetHost();
    if (host) {
      host->CloseBrowser(true);
    }
  }
  int64_t limit = now_ms() + 5000;
  while (!g_browsers.empty() && now_ms() < limit) {
    pump_once();
    if (from_script && EG(exception)) {
      break;
    }
    usleep(2000);
  }
  for (int i = 0; i < 20; ++i) {
    pump_once();
    usleep(1000);
  }
  g_browsers.clear();
  cl_bridge_shutdown();
  // PHP may still hold CEF objects; CEF must not be shut down while it does (cefweaver F-notes).
  cl::release_all_lib_objects();
  g_app = nullptr;
  CefShutdown();
  g_was_shut_down = true;
  cl::g_cef_running = false;
  g_context_ready = false;
  cl::drain_releases();
  return true;
}

}  // namespace

// -- called by CEF on any thread -----------------------------------------------------------------------------------

void cl_core_schedule_pump_work(int64_t delay_ms) {
  if (delay_ms <= 0) {
    g_work_now = true;
  } else {
    int64_t target = now_ms() + delay_ms;
    int64_t current = g_deadline_ms.load();
    while ((current < 0 || target < current) && !g_deadline_ms.compare_exchange_weak(current, target)) {
    }
  }
  wake();
}

size_t cl_core_browser_count() { return g_browsers.size(); }

void cl_core_note_browser(const CefRefPtr<CefBrowser>& browser, bool created) {
  if (created) {
    g_browsers[browser->GetIdentifier()] = browser;
    g_browser_seen = true;
  } else {
    g_browsers.erase(browser->GetIdentifier());
  }
}

std::vector<CefRefPtr<CefBrowser>> cl_core_browsers() {
  std::vector<CefRefPtr<CefBrowser>> list;
  for (auto& entry : g_browsers) {
    list.push_back(entry.second);
  }
  return list;
}

// -- the methods of the class Cef that are written by hand ----------------------------------------------------------------

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Cef_initialize, 0, 0, _IS_BOOL, 0)
ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, settings, Cefloom\\Settings, 1, "null")
ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, app, Cefloom\\App, 1, "null")
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Cef_void, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Cef_run, 0, 0, IS_VOID, 0)
ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, tick, IS_CALLABLE, 1, "null")
ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, quitWhenLastBrowserCloses, _IS_BOOL, 0, "true")
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Cef_wait, 0, 0, IS_VOID, 0)
ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, maxMilliseconds, IS_LONG, 0, "30")
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Cef_bool, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Cef_addSwitch, 0, 1, IS_VOID, 0)
ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, value, IS_STRING, 0, "\"\"")
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Cef_registerCustomScheme, 0, 1, IS_VOID, 0)
ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_LONG, 0, "Cefloom\\Types\\SchemeOptions::STANDARD | Cefloom\\Types\\SchemeOptions::SECURE | Cefloom\\Types\\SchemeOptions::CORS_ENABLED | Cefloom\\Types\\SchemeOptions::FETCH_ENABLED")
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Cef_expose, 0, 2, IS_VOID, 0)
ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_ARG_TYPE_INFO(0, function, IS_CALLABLE, 0)
ZEND_END_ARG_INFO()

ZEND_METHOD(Cef, initialize) {
  zend_object* settings_object = nullptr;
  zend_object* app_object = nullptr;
  ZEND_PARSE_PARAMETERS_START(0, 2)
  Z_PARAM_OPTIONAL
  Z_PARAM_OBJ_OF_CLASS_OR_NULL(settings_object, ce_CefSettings)
  Z_PARAM_OBJ_OF_CLASS_OR_NULL(app_object, ce_CefApp)
  ZEND_PARSE_PARAMETERS_END();

  if (cl::g_cef_running) {
    zend_throw_error(nullptr, "CEF is already initialized (it can be started once per process)");
    RETURN_THROWS();
  }
  if (g_was_shut_down) {
    zend_throw_error(nullptr, "CEF cannot be started again after Cef::shutdown(): it starts once per process");
    RETURN_THROWS();
  }
  std::string load_error;
  if (!cl_core_load_cef(&load_error)) {
    zend_throw_error(nullptr, "%s", load_error.c_str());
    RETURN_THROWS();
  }

  CefSettings settings;
  if (settings_object) {
    cl_from_zval_cef_settings_t(settings_object, &settings);
  }
  apply_defaults(settings);
  g_external_pump = settings.external_message_pump != 0;

  // Without its own subprocess CEF would start this PHP binary for its other processes.
  const std::string subprocess = CefString(&settings.browser_subprocess_path).ToString();
  if (access(subprocess.c_str(), X_OK) != 0) {
    zend_throw_error(nullptr,
                     "The CEF subprocess executable %s was not found. Install cefloom with its runtime files, set "
                     "CEFLOOM_HOME to the directory that holds them, or set browserSubprocessPath in the Settings.",
                     subprocess.c_str());
    RETURN_THROWS();
  }

  CefRefPtr<CefApp> user_app = app_object ? cl_make_CefApp(app_object) : nullptr;
  g_app = new NativeApp(user_app);

  // CEF is started in this process; its other processes run cefloomsubprocess (settings.browser_subprocess_path),
  // so the PHP binary is never started as a CEF child.
  static char argv0[] = "php";
  static char* argv[] = {argv0, nullptr};
  CefMainArgs main_args(1, argv);
  int code = CefExecuteProcess(main_args, g_app, nullptr);
  if (code >= 0) {
    exit(code);
  }
  if (g_wake_fd < 0) {
    g_wake_fd = eventfd(0, EFD_NONBLOCK | EFD_CLOEXEC);
  }
  g_quit = false;
  g_browser_seen = false;
  if (!CefInitialize(main_args, settings, g_app, nullptr)) {
    g_app = nullptr;
    RETURN_FALSE;
  }
  cl::g_cef_running = true;
  cl_bridge_init();
  RETURN_TRUE;
}

ZEND_METHOD(Cef, doMessageLoopWork) {
  ZEND_PARSE_PARAMETERS_NONE();
  if (!cl::g_cef_running) {
    zend_throw_error(nullptr, "CEF is not running; call Cef::initialize() first");
    RETURN_THROWS();
  }
  pump_once();
}

ZEND_METHOD(Cef, run) {
  zval* tick = nullptr;
  bool quit_when_last_closes = true;
  ZEND_PARSE_PARAMETERS_START(0, 2)
  Z_PARAM_OPTIONAL
  Z_PARAM_ZVAL(tick)
  Z_PARAM_BOOL(quit_when_last_closes)
  ZEND_PARSE_PARAMETERS_END();
  if (tick && Z_TYPE_P(tick) != IS_NULL && !zend_is_callable(tick, 0, nullptr)) {
    zend_argument_type_error(1, "must be a valid callback or null");
    RETURN_THROWS();
  }
  if (!cl::g_cef_running) {
    zend_throw_error(nullptr, "CEF is not running; call Cef::initialize() first");
    RETURN_THROWS();
  }
  g_quit = false;
  while (!g_quit && cl::g_cef_running) {
    pump_once();
    if (EG(exception)) {
      RETURN_THROWS();
    }
    if (tick && Z_TYPE_P(tick) != IS_NULL) {
      zval rv;
      ZVAL_UNDEF(&rv);
      cl::call_function(tick, 0, nullptr, &rv);
      bool stop = Z_TYPE(rv) == IS_FALSE;
      if (Z_TYPE(rv) != IS_UNDEF) {
        zval_ptr_dtor(&rv);
      }
      if (EG(exception)) {
        RETURN_THROWS();
      }
      if (stop) {
        break;
      }
    }
    if (quit_when_last_closes && g_browser_seen && g_browsers.empty()) {
      break;
    }
    wait_for_work(30);
  }
}

ZEND_METHOD(Cef, quit) {
  ZEND_PARSE_PARAMETERS_NONE();
  g_quit = true;
  wake();
}

ZEND_METHOD(Cef, wait) {
  zend_long max_ms = 30;
  ZEND_PARSE_PARAMETERS_START(0, 1)
  Z_PARAM_OPTIONAL
  Z_PARAM_LONG(max_ms)
  ZEND_PARSE_PARAMETERS_END();
  wait_for_work(static_cast<int>(std::max<zend_long>(0, std::min<zend_long>(max_ms, 60000))));
}

ZEND_METHOD(Cef, shutdown) {
  ZEND_PARSE_PARAMETERS_NONE();
  do_shutdown(true);
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Cef_string, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

// The version of CEF this module was built against; the runtime must be the same.
ZEND_METHOD(Cef, cefVersion) {
  ZEND_PARSE_PARAMETERS_NONE();
  RETURN_STRING(CEF_VERSION);
}

// The directory the module takes the CEF runtime from (where it looks first, see home_dir()).
ZEND_METHOD(Cef, runtimeDirectory) {
  ZEND_PARSE_PARAMETERS_NONE();
  { std::string dir = home_dir(); RETURN_STR(zend_string_init(dir.c_str(), dir.size(), 0)); }
}

// Where a runtime installed for the user goes (the cefloom-runtime tool installs there).
ZEND_METHOD(Cef, userRuntimeDirectory) {
  ZEND_PARSE_PARAMETERS_NONE();
  std::string dir = user_runtime_dir();
  RETURN_STR(zend_string_init(dir.c_str(), dir.size(), 0));
}

ZEND_METHOD(Cef, isRunning) {
  ZEND_PARSE_PARAMETERS_NONE();
  RETURN_BOOL(cl::g_cef_running.load());
}

ZEND_METHOD(Cef, addSwitch) {
  zend_string* name;
  zend_string* value = ZSTR_EMPTY_ALLOC();
  ZEND_PARSE_PARAMETERS_START(1, 2)
  Z_PARAM_STR(name)
  Z_PARAM_OPTIONAL
  Z_PARAM_STR(value)
  ZEND_PARSE_PARAMETERS_END();
  if (cl::g_cef_running) {
    zend_throw_error(nullptr, "Command line switches must be added before Cef::initialize()");
    RETURN_THROWS();
  }
  g_switches.emplace_back(std::string(ZSTR_VAL(name), ZSTR_LEN(name)), std::string(ZSTR_VAL(value), ZSTR_LEN(value)));
}

ZEND_METHOD(Cef, registerCustomScheme) {
  zend_string* name;
  zend_long options = CEF_SCHEME_OPTION_STANDARD | CEF_SCHEME_OPTION_SECURE | CEF_SCHEME_OPTION_CORS_ENABLED |
                      CEF_SCHEME_OPTION_FETCH_ENABLED;
  ZEND_PARSE_PARAMETERS_START(1, 2)
  Z_PARAM_STR(name)
  Z_PARAM_OPTIONAL
  Z_PARAM_LONG(options)
  ZEND_PARSE_PARAMETERS_END();
  if (cl::g_cef_running) {
    zend_throw_error(nullptr, "Custom schemes must be registered before Cef::initialize()");
    RETURN_THROWS();
  }
  std::string key(ZSTR_VAL(name), ZSTR_LEN(name));
  if (key.empty() || key.find_first_of(",:") != std::string::npos) {
    zend_argument_value_error(1, "must be a scheme name without ',' and ':'");
    RETURN_THROWS();
  }
  for (auto& scheme : g_schemes) {
    if (scheme.first == key) {
      scheme.second = static_cast<int>(options);
      return;
    }
  }
  g_schemes.emplace_back(key, static_cast<int>(options));
}

ZEND_METHOD(Cef, expose) {
  zend_string* name;
  zval* callable;
  ZEND_PARSE_PARAMETERS_START(2, 2)
  Z_PARAM_STR(name)
  Z_PARAM_ZVAL(callable)
  ZEND_PARSE_PARAMETERS_END();
  if (!zend_is_callable(callable, 0, nullptr)) {
    zend_argument_type_error(2, "must be a valid callback");
    RETURN_THROWS();
  }
  cl_bridge_expose(name, callable);
}

static const zend_function_entry core_methods[] = {
    ZEND_RAW_FENTRY("initialize", zim_Cef_initialize, arginfo_Cef_initialize, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC, NULL, NULL)
    ZEND_RAW_FENTRY("doMessageLoopWork", zim_Cef_doMessageLoopWork, arginfo_Cef_void, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC, NULL, NULL)
    ZEND_RAW_FENTRY("run", zim_Cef_run, arginfo_Cef_run, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC, NULL, NULL)
    ZEND_RAW_FENTRY("quit", zim_Cef_quit, arginfo_Cef_void, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC, NULL, NULL)
    ZEND_RAW_FENTRY("wait", zim_Cef_wait, arginfo_Cef_wait, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC, NULL, NULL)
    ZEND_RAW_FENTRY("shutdown", zim_Cef_shutdown, arginfo_Cef_void, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC, NULL, NULL)
    ZEND_RAW_FENTRY("isRunning", zim_Cef_isRunning, arginfo_Cef_bool, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC, NULL, NULL)
    ZEND_RAW_FENTRY("cefVersion", zim_Cef_cefVersion, arginfo_Cef_string, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC, NULL, NULL)
    ZEND_RAW_FENTRY("runtimeDirectory", zim_Cef_runtimeDirectory, arginfo_Cef_string, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC, NULL, NULL)
    ZEND_RAW_FENTRY("userRuntimeDirectory", zim_Cef_userRuntimeDirectory, arginfo_Cef_string, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC, NULL, NULL)
    ZEND_RAW_FENTRY("addSwitch", zim_Cef_addSwitch, arginfo_Cef_addSwitch, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC, NULL, NULL)
    ZEND_RAW_FENTRY("registerCustomScheme", zim_Cef_registerCustomScheme, arginfo_Cef_registerCustomScheme, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC, NULL, NULL)
    ZEND_RAW_FENTRY("expose", zim_Cef_expose, arginfo_Cef_expose, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC, NULL, NULL)
    ZEND_FE_END};

void cl_core_register() {
  zend_register_functions(ce_Cef, core_methods, &ce_Cef->function_table, MODULE_PERSISTENT);
}

void cl_core_rinit() {}

void cl_core_rshutdown() {
  if (cl::g_cef_running) {
    do_shutdown(false);
  }
  cl_bridge_shutdown();
}
