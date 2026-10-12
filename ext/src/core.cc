/**
 * @file
 * @brief Lifetime and message loop of CEF in a PHP process, and the hand-written methods of the PHP class Cef.
 *
 * The PHP process is the browser process of CEF and PHP's main thread is the CEF UI thread. The script drives CEF by
 * calling CefDoMessageLoopWork() (Cef::run does it in a loop): every few milliseconds by default, or when CEF announces
 * work (OnScheduleMessagePumpWork) if Settings::externalMessagePump is set. CEF asks for work from any thread; that
 * request is answered with atomics and an eventfd only and never touches PHP
 * (cautions.md, rules 1 and 2).
 */
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

/** Monotonic time in milliseconds at which CEF asked to run, or -1 for no request. Written by any thread. */
std::atomic<int64_t> g_deadline_ms{-1};  // when CEF wants to run (monotonic milliseconds), -1 for no request
/** True when CEF asked to run at once. Set by any thread, cleared by pump_once(). */
std::atomic<bool> g_work_now{false};
/**
 * True when the script asked for CEF's external message pump (Settings::externalMessagePump).
 * CEF then runs only the work it announced (OnScheduleMessagePumpWork) and nothing else iterates GLib's default main
 * context, which holds the X11 connection of the windows CEF owns: without it those windows get no input and a browser
 * does not finish closing. pump_once() and wait_for_work() therefore iterate that context themselves (verified-findings
 * F19, known-constraints K41 and K43). The default is the polling loop CEF recommends: Cef::doMessageLoopWork() at least
 * every few milliseconds.
 */
std::atomic<bool> g_external_pump{false};
/** True once CefShutdown() has run. CEF is not initialized again in the same process (cautions.md, rule 7: the cefweaver record is a segmentation fault). */
std::atomic<bool> g_was_shut_down{false};
/** Longest wait of wait_for_work() in milliseconds when CEF announces no work (polling mode). */
constexpr int kPollMs = 5;
/** Eventfd that interrupts wait_for_work(), or -1 before Cef::initialize(). Written by wake() from any thread. */
int g_wake_fd = -1;
/** Set by Cef::quit() to end Cef::run(). PHP thread only. */
bool g_quit = false;
/** True from OnContextInitialized() until shutdown. PHP thread only. */
bool g_context_ready = false;

/** Return the steady clock in milliseconds. Thread: any. */
int64_t now_ms() {
  return std::chrono::duration_cast<std::chrono::milliseconds>(std::chrono::steady_clock::now().time_since_epoch())
      .count();
}

/** Wake wait_for_work() through the eventfd. Thread: any. */
void wake() {
  if (g_wake_fd >= 0) {
    uint64_t one = 1;
    ssize_t ignored = write(g_wake_fd, &one, sizeof(one));
    (void)ignored;
  }
}

// -- the application object CEF gets ----------------------------------------------------------------------------

/** Command line switches added by Cef::addSwitch() as (name, value); an empty value is a bare switch. */
std::vector<std::pair<std::string, std::string>> g_switches;
/** Custom schemes registered by Cef::registerCustomScheme() as (name, cef_scheme_options_t). */
std::vector<std::pair<std::string, int>> g_schemes;  // custom schemes: name and cef_scheme_options_t

/**
 * Return g_schemes as "name:options,name:options" for the command line of child processes.
 * cefloomsubprocess registers the schemes again from it: every process has to know a custom scheme.
 */
std::string scheme_list() {
  std::string list;
  for (const auto& scheme : g_schemes) {
    if (!list.empty()) list += ",";
    list += scheme.first + ":" + std::to_string(scheme.second);
  }
  return list;
}

/**
 * Browser process handler of the extension; passes every event on to the handler of the user's App.
 * Adds the scheme list to the command line of child processes and feeds the message pump requests to
 * cl_core_schedule_pump_work().
 */
class NativeBrowserProcessHandler : public Fwd_CefBrowserProcessHandler {
 public:
  /** Forward CEF's request for message pump work to cl_core_schedule_pump_work(). Thread: any; never calls PHP. */
  void OnScheduleMessagePumpWork(int64_t delay_ms) override { cl_core_schedule_pump_work(delay_ms); }
  /** Record that the CEF context is ready, then forward. Thread: CEF UI. */
  void OnContextInitialized() override {
    g_context_ready = true;
    Fwd_CefBrowserProcessHandler::OnContextInitialized();
  }
  /** Append the --cefloom-schemes switch when custom schemes exist, then forward. */
  void OnBeforeChildProcessLaunch(CefRefPtr<CefCommandLine> command_line) override {
    if (!g_schemes.empty()) {
      command_line->AppendSwitchWithValue("cefloom-schemes", scheme_list());
    }
    Fwd_CefBrowserProcessHandler::OnBeforeChildProcessLaunch(command_line);
  }
  /** Set the user's handler that unhandled events are passed on to (null for none). */
  void set_forward(CefRefPtr<CefBrowserProcessHandler> next) { forward_ = next; }

 private:
  /** Thread-safe reference counting of CEF. */
  IMPLEMENT_REFCOUNTING(NativeBrowserProcessHandler);
};

/** CefApp of the extension: applies the switches and schemes of the script, then passes everything on to the user's App. */
class NativeApp : public Fwd_CefApp {
 public:
  /** Wrap |user|, the CefApp made from the script's App (null for none). */
  explicit NativeApp(CefRefPtr<CefApp> user) : handler_(new NativeBrowserProcessHandler()) { forward_ = user; }

  /**
   * Append the switches of Cef::addSwitch() to the browser process command line, then forward.
   * Also adds disable-chrome-login-prompt unless the script set it.
   */
  void OnBeforeCommandLineProcessing(const CefString& process_type, CefRefPtr<CefCommandLine> command_line) override {
    if (process_type.empty()) {
      for (const auto& entry : g_switches) {
        if (entry.second.empty()) {
          command_line->AppendSwitch(entry.first);
        } else {
          command_line->AppendSwitchWithValue(entry.first, entry.second);
        }
      }
      // Without this the Chrome runtime asks for credentials with Chrome's own login prompt, which an embedder never
      // shows: the request waits for ever and RequestHandler::getAuthCredentials is never called (verified-findings F20).
      if (!command_line->HasSwitch("disable-chrome-login-prompt")) {
        command_line->AppendSwitch("disable-chrome-login-prompt");
      }
    }
    Fwd_CefApp::OnBeforeCommandLineProcessing(process_type, command_line);
  }

  /** Register the schemes of Cef::registerCustomScheme(). Thread: runs in every CEF process. */
  void OnRegisterCustomSchemes(CefRawPtr<CefSchemeRegistrar> registrar) override {
    for (const auto& scheme : g_schemes) {
      registrar->AddCustomScheme(scheme.first, scheme.second);
    }
  }

  /** Return the extension's browser process handler, chained to the user's. */
  CefRefPtr<CefBrowserProcessHandler> GetBrowserProcessHandler() override {
    handler_->set_forward(forward_ ? forward_->GetBrowserProcessHandler() : nullptr);
    return handler_;
  }

 private:
  /** Handler returned by GetBrowserProcessHandler(). */
  CefRefPtr<NativeBrowserProcessHandler> handler_;
  /** Thread-safe reference counting of CEF. */
  IMPLEMENT_REFCOUNTING(NativeApp);
};

/** The CefApp passed to CefInitialize(); null before Cef::initialize() and after shutdown. PHP thread only. */
CefRefPtr<NativeApp> g_app;

// -- the browsers -------------------------------------------------------------------------------------------------

/** Live browsers by identifier, kept by cl_core_note_browser(). Holds one reference each. PHP thread only. */
std::map<int, CefRefPtr<CefBrowser>> g_browsers;
/** True once a browser has been created since Cef::initialize(); lets Cef::run() quit when the last one closes. */
bool g_browser_seen = false;

// -- paths -------------------------------------------------------------------------------------------------------

/** Return |path| resolved with realpath(), or |path| itself if it cannot be resolved. */
std::string absolute_dir(const std::string& path) {
  char resolved[PATH_MAX];
  return realpath(path.c_str(), resolved) ? std::string(resolved) : path;
}

/**
 * Return the directory that holds the module, libcef.so and the CEF resources (the layout of a cefloom install).
 * Falls back to the working directory if the module path is unknown.
 */
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

/**
 * Return the directory where a runtime installed for the user lives: <data home>/cefloom/runtime/<CEF version>.
 * The version is part of the path because the module only works with the runtime it was built against.
 */
std::string user_runtime_dir() {
  const char* xdg = getenv("XDG_DATA_HOME");
  const char* user_home = getenv("HOME");
  std::string base = xdg && *xdg ? xdg : (user_home && *user_home ? std::string(user_home) + "/.local/share" : "/tmp");
  return base + "/cefloom/runtime/" + std::to_string(CEF_VERSION_MAJOR) + "." + std::to_string(CEF_VERSION_MINOR) + "." +
         std::to_string(CEF_VERSION_PATCH);
}

/** Return whether |dir| holds a readable libcef.so. */
bool has_runtime(const std::string& dir) { return access((dir + "/libcef.so").c_str(), R_OK) == 0; }

/**
 * Return the directory of the CEF runtime (libcef.so, the resources, cefloomsubprocess).
 * CEFLOOM_HOME if set, else the directory of the module if the runtime is there (a build directory), else the user's
 * runtime directory if it holds one, else the directory of the module.
 */
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
/** Handle of the dlopen()ed libcef.so, or null while it is not loaded (CEFLOOM_LAZY_LIBCEF builds). */
void* g_libcef = nullptr;
#endif

}  // namespace

#ifdef CEFLOOM_LAZY_LIBCEF
/** Hand the libcef.so handle to the lazy stubs (CEFLOOM_LAZY_LIBCEF builds). */
void cl_lazy_set_libcef(void* handle);  // lazy_stubs.cc
#endif

/**
 * Make libcef.so available and configure the CEF API version.
 * A build with CEFLOOM_LAZY_LIBCEF loads libcef.so from home_dir() the first time it is needed; any other build has it linked.
 * @param error Receives the reason on failure; may be null.
 * @return true if CEF is usable.
 * @note Thread: PHP.
 */
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

/** Set the CEF string |field| to |value| unless it already has a value. */
void set_if_empty(cef_string_t* field, const std::string& value) {
  if (field->length == 0) {
    CefString(field).FromString(value);
  }
}

/**
 * Fill the fields of |s| that the script left unset.
 * Forces the external-pump-compatible loop (no multi-threaded loop) and no_sandbox, and points the subprocess, resources,
 * locales and root cache paths at the runtime and the user's cache directory.
 */
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

/** Layout of GLib's GPollFD, so that GLib's headers are not needed to build. */
struct PollFd {  // GPollFD
  /** File descriptor to poll. */
  int fd;
  /** Requested events (POLLIN, ...). */
  unsigned short events;
  /** Returned events. */
  unsigned short revents;
};
/**
 * GLib main context functions looked up in the process with dlsym(), so the extension does not need GLib to build.
 * libcef links GLib; the X11 connection of its windows is a source of the default main context, and with the external
 * message pump nothing else would iterate that context (windows made by CEF then get no input, and a browser does not
 * finish closing).
 */
struct Glib {
  /** g_main_context_default(). */
  void* (*context_default)();
  /** g_main_context_acquire(). */
  int (*acquire)(void*);
  /** g_main_context_release(). */
  void (*release)(void*);
  /** g_main_context_prepare(). */
  int (*prepare)(void*, int*);
  /** g_main_context_query(). */
  int (*query)(void*, int, int*, PollFd*, int);
  /** g_main_context_check(). */
  int (*check)(void*, int, PollFd*, int);
  /** g_main_context_dispatch(). */
  void (*dispatch)(void*);
  /** g_main_context_iteration(). */
  int (*iteration)(void*, int);
  /** True when every function above was found. */
  bool ok = false;
};

/** Return the GLib functions of the process (looked up once; check Glib::ok). */
const Glib& glib() {
  static Glib g = [] {
    Glib r;
    r.context_default = reinterpret_cast<decltype(r.context_default)>(dlsym(RTLD_DEFAULT, "g_main_context_default"));
    r.acquire = reinterpret_cast<decltype(r.acquire)>(dlsym(RTLD_DEFAULT, "g_main_context_acquire"));
    r.release = reinterpret_cast<decltype(r.release)>(dlsym(RTLD_DEFAULT, "g_main_context_release"));
    r.prepare = reinterpret_cast<decltype(r.prepare)>(dlsym(RTLD_DEFAULT, "g_main_context_prepare"));
    r.query = reinterpret_cast<decltype(r.query)>(dlsym(RTLD_DEFAULT, "g_main_context_query"));
    r.check = reinterpret_cast<decltype(r.check)>(dlsym(RTLD_DEFAULT, "g_main_context_check"));
    r.dispatch = reinterpret_cast<decltype(r.dispatch)>(dlsym(RTLD_DEFAULT, "g_main_context_dispatch"));
    r.iteration = reinterpret_cast<decltype(r.iteration)>(dlsym(RTLD_DEFAULT, "g_main_context_iteration"));
    r.ok = r.context_default && r.acquire && r.release && r.prepare && r.query && r.check && r.dispatch && r.iteration;
    return r;
  }();
  return g;
}

/** Run the pending sources of GLib's default main context without blocking (at most 100 iterations). */
void iterate_glib() {
  const Glib& g = glib();
  if (g.ok) {
    for (int i = 0; i < 100 && g.iteration(nullptr, 0); ++i) {
    }
  }
}

/**
 * Run one iteration of CEF's message loop (CefDoMessageLoopWork()) and the queued releases.
 * Records the pump entry time in cl::g_pump_since_ms for the F14 timeout fallback of cl::run_on_php_thread().
 * @note Thread: PHP.
 */
void pump_once() {
  cl::drain_releases();
  g_work_now = false;
  int64_t deadline = g_deadline_ms.load();
  if (deadline >= 0 && deadline <= now_ms()) {
    g_deadline_ms = -1;
  }
  cl::g_pump_since_ms = cl::steady_ms();
  CefDoMessageLoopWork();
  if (g_external_pump.load()) {
    iterate_glib();
  }
  cl::g_pump_since_ms = 0;
  cl::drain_releases();
}

/**
 * Sleep until CEF asks for work, a wake up arrives, or |max_ms| have passed.
 * In the external pump mode it also waits on GLib's sockets so that window input is not delayed.
 * @note Thread: PHP.
 */
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
  const Glib& g = glib();
  void* context = g_external_pump.load() && g.ok ? g.context_default() : nullptr;
  if (context && g.acquire(context)) {
    // Wait for the wake up of CEF and for the sockets of GLib's main context (the X11 connection) together, so that input
    // does not wait for the end of a long wait.
    int priority = 0;
    int glib_timeout = -1;
    std::vector<PollFd> glib_fds(16);
    if (g.prepare(context, &priority)) {
      max_ms = 0;
    }
    int count = g.query(context, priority, &glib_timeout, glib_fds.data(), static_cast<int>(glib_fds.size()));
    if (count > static_cast<int>(glib_fds.size())) {
      glib_fds.resize(count);
      count = g.query(context, priority, &glib_timeout, glib_fds.data(), count);
    }
    if (glib_timeout >= 0) {
      max_ms = std::min(max_ms, glib_timeout);
    }
    std::vector<struct pollfd> fds;
    fds.push_back({g_wake_fd, POLLIN, 0});
    for (int i = 0; i < count; ++i) {
      fds.push_back({glib_fds[i].fd, static_cast<short>(glib_fds[i].events), 0});
    }
    poll(fds.data(), fds.size(), max_ms);
    for (int i = 0; i < count; ++i) {
      glib_fds[i].revents = static_cast<unsigned short>(fds[i + 1].revents);
    }
    if (fds[0].revents & POLLIN) {
      uint64_t value;
      ssize_t ignored = read(g_wake_fd, &value, sizeof(value));
      (void)ignored;
    }
    if (g.check(context, priority, glib_fds.data(), count)) {
      g.dispatch(context);
    }
    g.release(context);
    return;
  }
  struct pollfd fd = {g_wake_fd, POLLIN, 0};
  if (poll(&fd, 1, max_ms) > 0) {
    uint64_t value;
    ssize_t ignored = read(g_wake_fd, &value, sizeof(value));
    (void)ignored;
  }
}

/**
 * Close the browsers, pump until they are gone (at most 5 s), release the library objects and call CefShutdown().
 * @param from_script True when a PHP function called it: a pending exception then ends the wait for the browsers early.
 * @return true (also if CEF was not running).
 * @note Thread: PHP.
 */
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

/**
 * Record CEF's request for message pump work and wake the PHP thread.
 * @param delay_ms Delay in milliseconds; 0 or less means at once.
 * @note Thread: any. Never touches PHP (cautions.md, rules 1 and 2).
 */
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

/** Return the number of live browsers. Thread: PHP. */
size_t cl_core_browser_count() { return g_browsers.size(); }

/**
 * Add or remove |browser| in the list of live browsers.
 * @param created True after OnAfterCreated, false before the browser goes away.
 * @note Thread: PHP.
 */
void cl_core_note_browser(const CefRefPtr<CefBrowser>& browser, bool created) {
  if (created) {
    g_browsers[browser->GetIdentifier()] = browser;
    g_browser_seen = true;
  } else {
    g_browsers.erase(browser->GetIdentifier());
  }
}

/** Return a snapshot of the live browsers. Thread: PHP. */
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

/**
 * Cef::initialize(?Settings $settings = null, ?App $app = null): bool
 *
 * Start CEF in this process (once per process). Settings left unset get defaults: subprocess, resources and locales
 * beside the runtime, root cache path under $XDG_CACHE_HOME or ~/.cache (cefloom/), no sandbox.
 * @param settings Settings of CEF, or null for the defaults.
 * @param app Handler for the events of the CEF application, or null.
 * @return true once CEF runs, false if CefInitialize() failed.
 * @throws Error if CEF already runs or was shut down before, if the runtime cannot be loaded, or if the subprocess
 *         executable does not exist.
 * @note Thread: PHP main thread, which becomes the CEF UI thread.
 */
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

/**
 * Cef::doMessageLoopWork(): void
 *
 * Run one iteration of CEF's message loop. A pending exception of a PHP callback surfaces when it returns.
 * @throws Error if CEF is not running.
 */
ZEND_METHOD(Cef, doMessageLoopWork) {
  ZEND_PARSE_PARAMETERS_NONE();
  if (!cl::g_cef_running) {
    zend_throw_error(nullptr, "CEF is not running; call Cef::initialize() first");
    RETURN_THROWS();
  }
  pump_once();
}

/**
 * Cef::run(?callable $tick = null, bool $quitWhenLastBrowserCloses = true): void
 *
 * Run the message loop until Cef::quit(), until |tick| returns false, until the last browser closes, or until CEF shuts down.
 * @param tick Called after every iteration with no arguments; returning exactly false ends the loop.
 * @param quitWhenLastBrowserCloses End the loop when the last browser closed (only after one has been created).
 * @throws TypeError if |tick| is not callable.
 * @throws Error if CEF is not running.
 * @throws Throwable whatever a callback or |tick| leaves pending; the loop ends.
 */
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

/**
 * Cef::quit(): void
 *
 * Ask Cef::run() to return after its current iteration.
 * @note Thread: PHP.
 */
ZEND_METHOD(Cef, quit) {
  ZEND_PARSE_PARAMETERS_NONE();
  g_quit = true;
  wake();
}

/**
 * Cef::wait(int $maxMilliseconds = 30): void
 *
 * Sleep until CEF asks for work or a wake up arrives, at most |maxMilliseconds| (clamped to 0..60000). Without
 * Settings::externalMessagePump the wait is at most kPollMs (5 ms), because CEF announces no work then.
 */
ZEND_METHOD(Cef, wait) {
  zend_long max_ms = 30;
  ZEND_PARSE_PARAMETERS_START(0, 1)
  Z_PARAM_OPTIONAL
  Z_PARAM_LONG(max_ms)
  ZEND_PARSE_PARAMETERS_END();
  wait_for_work(static_cast<int>(std::max<zend_long>(0, std::min<zend_long>(max_ms, 60000))));
}

/**
 * Cef::shutdown(): void
 *
 * Close all browsers and shut CEF down. Library objects the script still holds lose their native object. CEF cannot be
 * started again in this process. Does nothing if CEF is not running.
 */
ZEND_METHOD(Cef, shutdown) {
  ZEND_PARSE_PARAMETERS_NONE();
  do_shutdown(true);
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Cef_string, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

/**
 * Cef::cefVersion(): string
 *
 * Return the version of CEF this module was built against; the runtime must be the same.
 */
ZEND_METHOD(Cef, cefVersion) {
  ZEND_PARSE_PARAMETERS_NONE();
  RETURN_STRING(CEF_VERSION);
}

/**
 * Cef::runtimeDirectory(): string
 *
 * Return the directory the module takes the CEF runtime from (CEFLOOM_HOME, beside the module, or the user's runtime
 * directory; see home_dir()).
 */
ZEND_METHOD(Cef, runtimeDirectory) {
  ZEND_PARSE_PARAMETERS_NONE();
  { std::string dir = home_dir(); RETURN_STR(zend_string_init(dir.c_str(), dir.size(), 0)); }
}

/**
 * Cef::userRuntimeDirectory(): string
 *
 * Return the directory where a runtime installed for the user goes (the cefloom-runtime tool installs there).
 */
ZEND_METHOD(Cef, userRuntimeDirectory) {
  ZEND_PARSE_PARAMETERS_NONE();
  std::string dir = user_runtime_dir();
  RETURN_STR(zend_string_init(dir.c_str(), dir.size(), 0));
}

/**
 * Cef::isRunning(): bool
 *
 * Return whether CEF has been initialized and not yet shut down.
 */
ZEND_METHOD(Cef, isRunning) {
  ZEND_PARSE_PARAMETERS_NONE();
  RETURN_BOOL(cl::g_cef_running.load());
}

/**
 * Cef::addSwitch(string $name, string $value = ""): void
 *
 * Add a command line switch for the browser process; an empty |value| adds a bare switch.
 * @throws Error if CEF already runs: switches must be added before Cef::initialize().
 */
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

/**
 * Cef::registerCustomScheme(string $name, int $options = STANDARD | SECURE | CORS_ENABLED | FETCH_ENABLED): void
 *
 * Register a custom scheme in every CEF process. Registering a name again replaces its options.
 * @param name Scheme name; must not be empty or contain ',' or ':'.
 * @param options Combination of SchemeOptions constants.
 * @throws Error if CEF already runs: schemes must be registered before Cef::initialize().
 * @throws ValueError if |name| is not a valid scheme name.
 */
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

/**
 * Cef::expose(string $name, callable $function): void
 *
 * Make a PHP function callable from JavaScript as cefloom.call(name, ...args). The arguments and the result are JSON;
 * an exception becomes a rejected Promise. Exposing a name again replaces the function.
 * @throws TypeError if |function| is not callable.
 */
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

/** Methods of the class Cef that are written by hand, registered by cl_core_register(). */
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

/** Register the hand-written methods in the class Cef. Thread: PHP, in MINIT. */
void cl_core_register() {
  zend_register_functions(ce_Cef, core_methods, &ce_Cef->function_table, MODULE_PERSISTENT);
}

/** Run at request start. Nothing to do. Thread: PHP. */
void cl_core_rinit() {}

/**
 * Shut CEF down at request end if the script did not, then release the bridge.
 * @note Thread: PHP. A pending exception does not end the wait for the browsers to close (from_script is false).
 */
void cl_core_rshutdown() {
  if (cl::g_cef_running) {
    do_shutdown(false);
  }
  cl_bridge_shutdown();
}
