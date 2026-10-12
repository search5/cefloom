/**
 * @file
 * @brief The JS bridge and the client that carries it.
 *
 * JavaScript calls `cefloom.call(name, ...args)` (defined by cefloomsubprocess in every frame), which sends a query
 * through CEF's message router. This file answers it in the browser process: the query is JSON
 * {"name": ..., "args": [...]}, the PHP function exposed under that name is called on the PHP thread, and its result goes
 * back as JSON. A PHP exception becomes a rejected Promise in JavaScript.
 *
 * The router must see a few events of every browser (a closed browser, a navigation, a crashed renderer), so every
 * browser gets NativeClient, which handles those and passes everything else on to the client of the PHP script.
 */
#include "core.h"

extern "C" {
#include "ext/json/php_json.h"
}

namespace {

/** PHP callables by name, set by Cef::expose(). Each entry owns one reference. PHP thread only. */
std::map<std::string, zval> g_exposed;  // PHP callables by name
/** Message router of the bridge; null outside cl_bridge_init() and cl_bridge_shutdown(). */
CefRefPtr<CefMessageRouterBrowserSide> g_router;

/** Return the message of exception |ex|, or "error" if it has none. */
std::string exception_message(zend_object* ex) {
  zval rv;
  zval* message = zend_read_property_ex(zend_ce_exception, ex, ZSTR_KNOWN(ZEND_STR_MESSAGE), true, &rv);
  if (message && Z_TYPE_P(message) == IS_STRING) {
    return std::string(Z_STRVAL_P(message), Z_STRLEN_P(message));
  }
  return "error";
}

/** Router handler that answers a JS query by calling the PHP function exposed under its name. */
class Bridge : public CefMessageRouterBrowserSide::Handler {
 public:
  /**
   * Answer a query from JavaScript through handle() on the PHP thread.
   * If PHP cannot run it (shutting down, or the F14 timeout fallback of cl::run_on_php_thread()) the query fails with 500, so
   * the JavaScript promise never waits for ever.
   * @return true: the query is always handled here.
   */
  bool OnQuery(CefRefPtr<CefBrowser> browser, CefRefPtr<CefFrame> frame, int64_t query_id, const CefString& request,
               bool persistent, CefRefPtr<Callback> callback) override {
    std::string text = request.ToString();
    if (!cl::run_on_php_thread([&]() { handle(text, callback); })) {
      callback->Failure(500, "PHP is not available");  // the JavaScript promise must not wait for ever
    }
    return true;
  }

 private:
  /**
   * Decode |text|, call the exposed function and answer |callback| with its JSON result.
   * Fails the query with 400 (invalid request), 404 (unknown function) or 500 (PHP unavailable, the call threw, or the
   * result is not JSON encodable); the message of a PHP exception is the failure text. An exit() stays pending.
   * @note Thread: PHP.
   */
  static void handle(const std::string& text, CefRefPtr<Callback> callback) {
    if (!cl::g_php_alive || EG(exception)) {
      callback->Failure(500, "PHP is not available");
      return;
    }
    zval doc;
    ZVAL_UNDEF(&doc);
    if (php_json_decode_ex(&doc, text.c_str(), text.size(), PHP_JSON_OBJECT_AS_ARRAY, 512) != SUCCESS ||
        Z_TYPE(doc) != IS_ARRAY) {
      if (Z_TYPE(doc) != IS_UNDEF) zval_ptr_dtor(&doc);
      if (EG(exception)) zend_clear_exception();
      callback->Failure(400, "invalid request");
      return;
    }
    zval* name = zend_hash_str_find(Z_ARRVAL(doc), "name", sizeof("name") - 1);
    zval* args = zend_hash_str_find(Z_ARRVAL(doc), "args", sizeof("args") - 1);
    auto found = name && Z_TYPE_P(name) == IS_STRING
                     ? g_exposed.find(std::string(Z_STRVAL_P(name), Z_STRLEN_P(name)))
                     : g_exposed.end();
    if (found == g_exposed.end()) {
      zval_ptr_dtor(&doc);
      callback->Failure(404, "unknown function");
      return;
    }

    uint32_t argc = args && Z_TYPE_P(args) == IS_ARRAY ? zend_hash_num_elements(Z_ARRVAL_P(args)) : 0;
    zval* argv = argc ? static_cast<zval*>(safe_emalloc(argc, sizeof(zval), 0)) : nullptr;
    uint32_t i = 0;
    if (argc) {
      zval* item;
      ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(args), item) { ZVAL_COPY(&argv[i++], item); }
      ZEND_HASH_FOREACH_END();
    }
    zval rv;
    ZVAL_UNDEF(&rv);
    bool ok = cl::call_function(&found->second, argc, argv, &rv);
    for (uint32_t k = 0; k < argc; ++k) {
      zval_ptr_dtor(&argv[k]);
    }
    if (argv) efree(argv);
    zval_ptr_dtor(&doc);

    if (!ok) {
      if (EG(exception)) {
        zend_object* ex = EG(exception);
        if (zend_is_unwind_exit(ex) || zend_is_graceful_exit(ex)) {
          callback->Failure(500, "the PHP script is exiting");  // the exit stays pending and unwinds PHP
        } else {
          std::string message = exception_message(ex);
          zend_clear_exception();
          callback->Failure(500, message);
        }
      } else {
        callback->Failure(500, "the call failed");
      }
      if (Z_TYPE(rv) != IS_UNDEF) zval_ptr_dtor(&rv);
      return;
    }

    smart_str buffer = {0};
    zval null_value;
    ZVAL_NULL(&null_value);
    // php_json_encode_ex() reports a value it cannot write (NAN, a bad UTF-8 string) only in the error code.
    bool encoded = php_json_encode_ex(&buffer, Z_TYPE(rv) == IS_UNDEF ? &null_value : &rv, 0, 512) == SUCCESS &&
                   JSON_G(error_code) == PHP_JSON_ERROR_NONE;
    if (Z_TYPE(rv) != IS_UNDEF) zval_ptr_dtor(&rv);
    if (!encoded) {
      smart_str_free(&buffer);
      callback->Failure(500, "the result cannot be converted to JSON");
      return;
    }
    smart_str_0(&buffer);
    callback->Success(std::string(ZSTR_VAL(buffer.s), ZSTR_LEN(buffer.s)));
    smart_str_free(&buffer);
  }
};

/** The handler registered with the router. The router keeps a plain pointer: it is not reference counted. */
std::unique_ptr<Bridge> g_bridge;  // the router keeps a plain pointer: the handler is not reference counted

/**
 * CefClient given to every browser: takes part in its life span and request events for the router, and passes
 * everything to the client of the PHP script (which may be null).
 */
class NativeClient : public Fwd_CefClient, public Fwd_CefLifeSpanHandler, public Fwd_CefRequestHandler {
 public:
  /**
   * Wrap |user|, the CefClient made from the script's Client (null for none).
   * CEF's getClient() answers with the script's client through the alias registered here.
   */
  explicit NativeClient(CefRefPtr<CefClient> user) {
    Fwd_CefClient::forward_ = user;
    cl::ProxyBase::alias(static_cast<CefClient*>(this), user.get());
  }
  /** Remove the alias of the script's client. */
  ~NativeClient() override { cl::ProxyBase::unalias(static_cast<CefClient*>(this)); }

  // Fwd_CefClient: the three handlers this class takes part in
  /** Return this object as the life span handler, chained to the one of the user's client. */
  CefRefPtr<CefLifeSpanHandler> GetLifeSpanHandler() override {
    CefRefPtr<CefClient> user = Fwd_CefClient::forward_;
    Fwd_CefLifeSpanHandler::forward_ = user ? user->GetLifeSpanHandler() : nullptr;
    return this;
  }
  /** Return this object as the request handler, chained to the one of the user's client. */
  CefRefPtr<CefRequestHandler> GetRequestHandler() override {
    CefRefPtr<CefClient> user = Fwd_CefClient::forward_;
    Fwd_CefRequestHandler::forward_ = user ? user->GetRequestHandler() : nullptr;
    return this;
  }
  /** Offer the message to the router first, then to the user's client. Return true if either handled it. */
  bool OnProcessMessageReceived(CefRefPtr<CefBrowser> browser, CefRefPtr<CefFrame> frame, CefProcessId source_process,
                                CefRefPtr<CefProcessMessage> message) override {
    if (g_router && g_router->OnProcessMessageReceived(browser, frame, source_process, message)) {
      return true;
    }
    return Fwd_CefClient::OnProcessMessageReceived(browser, frame, source_process, message);
  }

  // Fwd_CefLifeSpanHandler
  /** Add the new browser to the list of live browsers, then forward. */
  void OnAfterCreated(CefRefPtr<CefBrowser> browser) override {
    cl_core_note_browser(browser, true);
    Fwd_CefLifeSpanHandler::OnAfterCreated(browser);
  }
  /** Tell the router the browser closes, forward, then remove it from the list of live browsers. */
  void OnBeforeClose(CefRefPtr<CefBrowser> browser) override {
    if (g_router) {
      g_router->OnBeforeClose(browser);
    }
    Fwd_CefLifeSpanHandler::OnBeforeClose(browser);
    cl_core_note_browser(browser, false);
  }

  // Fwd_CefRequestHandler
  /** Tell the router about the navigation, then forward and return the user's answer. */
  bool OnBeforeBrowse(CefRefPtr<CefBrowser> browser, CefRefPtr<CefFrame> frame, CefRefPtr<CefRequest> request,
                      bool user_gesture, bool is_redirect) override {
    if (g_router) {
      g_router->OnBeforeBrowse(browser, frame);
    }
    return Fwd_CefRequestHandler::OnBeforeBrowse(browser, frame, request, user_gesture, is_redirect);
  }
  /** Tell the router the renderer is gone (it fails that browser's open queries), then forward. */
  void OnRenderProcessTerminated(CefRefPtr<CefBrowser> browser, TerminationStatus status, int error_code,
                                 const CefString& error_string) override {
    if (g_router) {
      g_router->OnRenderProcessTerminated(browser);
    }
    Fwd_CefRequestHandler::OnRenderProcessTerminated(browser, status, error_code, error_string);
  }

 private:
  /** Thread-safe reference counting of CEF. */
  IMPLEMENT_REFCOUNTING(NativeClient);
};

}  // namespace

/**
 * Return the client every browser gets: a NativeClient in front of |user|.
 * @param user The client made from the script's Client, or null.
 */
CefRefPtr<CefClient> cl_bridge_wrap_client(CefRefPtr<CefClient> user) { return new NativeClient(user); }

/** Create the message router and register the bridge handler. Call after CefInitialize(). Thread: PHP. */
void cl_bridge_init() {
  CefMessageRouterConfig config;
  g_router = CefMessageRouterBrowserSide::Create(config);
  g_bridge.reset(new Bridge());
  g_router->AddHandler(g_bridge.get(), false);
}

/**
 * Remove the bridge handler and drop the exposed functions.
 * Call before CefShutdown(); safe to call twice. The callables are released only while PHP is alive.
 * @note Thread: PHP.
 */
void cl_bridge_shutdown() {
  if (g_router && g_bridge) {
    g_router->RemoveHandler(g_bridge.get());
  }
  g_bridge.reset();
  g_router = nullptr;
  for (auto& entry : g_exposed) {
    if (cl::g_php_alive) {
      zval_ptr_dtor(&entry.second);
    }
  }
  g_exposed.clear();
}

/**
 * Expose the PHP |callable| to JavaScript under |name|, replacing a function exposed under the same name.
 * Takes its own reference to |callable|.
 * @note Thread: PHP.
 */
void cl_bridge_expose(zend_string* name, zval* callable) {
  std::string key(ZSTR_VAL(name), ZSTR_LEN(name));
  auto found = g_exposed.find(key);
  if (found != g_exposed.end()) {
    zval_ptr_dtor(&found->second);
    g_exposed.erase(found);
  }
  zval copy;
  ZVAL_COPY(&copy, callable);
  g_exposed.emplace(key, copy);
}

// -- Cef\BrowserHost::createBrowser / createBrowserSync ------------------------------------------------------------

namespace {

/** The six arguments of CefBrowserHost::CreateBrowser() converted from PHP, kept alive for the call. */
struct BrowserArgs {
  /** Window parameters. */
  CefWindowInfo window_info;
  /** Browser settings. */
  CefBrowserSettings settings;
  /** Initial URL. */
  CefString url;
  /** The NativeClient wrapping the script's client. */
  CefRefPtr<CefClient> client;
  /** Extra info for the render process, or null. */
  CefRefPtr<CefDictionaryValue> extra_info;
  /** Request context, or null for the global one. */
  CefRefPtr<CefRequestContext> request_context;
};

/**
 * Parse the six arguments of CreateBrowser and CreateBrowserSync into |out|.
 * @return false, with a PHP exception pending, if the arguments are wrong or CEF is not running.
 */
bool parse_browser_args(INTERNAL_FUNCTION_PARAMETERS, BrowserArgs* out) {
  zend_object *window_info, *client, *settings, *extra, *context;
  zend_string* url;
  ZEND_PARSE_PARAMETERS_START(6, 6)
  Z_PARAM_OBJ_OF_CLASS(window_info, ce_CefWindowInfo)
  Z_PARAM_OBJ_OF_CLASS_OR_NULL(client, ce_CefClient)
  Z_PARAM_STR(url)
  Z_PARAM_OBJ_OF_CLASS(settings, ce_CefBrowserSettings)
  Z_PARAM_OBJ_OF_CLASS_OR_NULL(extra, ce_CefDictionaryValue)
  Z_PARAM_OBJ_OF_CLASS_OR_NULL(context, ce_CefRequestContext)
  ZEND_PARSE_PARAMETERS_END_EX(return false);
  if (!cl::g_cef_running) {
    zend_throw_error(nullptr, "CEF is not running; call Cef::initialize() first");
    return false;
  }
  cl_from_zval_cef_window_info_t(window_info, &out->window_info);
  cl_from_zval_cef_browser_settings_t(settings, &out->settings);
  out->url = cl::to_cef(url);
  out->client = cl_bridge_wrap_client(client ? cl_make_CefClient(client) : nullptr);
  out->extra_info = cl::lib_ptr<CefDictionaryValue>(extra, 0);
  out->request_context = cl::lib_ptr<CefRequestContext>(context, 1);  // a PreferenceManager is its parent
  return true;
}

}  // namespace

/**
 * Throw Error and return false unless CEF is running.
 * BrowserArgs holds C++ structs of CEF whose destructors call libcef: they must not exist while CEF is not running (the
 * library is not even loaded then), so this check comes before them.
 */
static bool require_running() {
  if (!cl::g_cef_running) {
    zend_throw_error(nullptr, "CEF is not running; call Cef::initialize() first");
    return false;
  }
  return true;
}

/**
 * CefBrowserHost::CreateBrowser(WindowInfo $windowInfo, ?Client $client, string $url, BrowserSettings $settings,
 * ?DictionaryValue $extraInfo, ?RequestContext $requestContext): bool
 *
 * Create a browser asynchronously; it appears in Client callbacks (OnAfterCreated).
 * @return The value CefBrowserHost::CreateBrowser() returns.
 * @throws Error if CEF is not running.
 * @throws ArgumentCountError if the number of arguments is not six.
 * @throws TypeError if an argument has the wrong type.
 */
ZEND_METHOD(CefBrowserHost, CreateBrowser) {
  if (!require_running()) {
    RETURN_THROWS();
  }
  BrowserArgs args;
  if (!parse_browser_args(INTERNAL_FUNCTION_PARAM_PASSTHRU, &args)) {
    RETURN_THROWS();
  }
  RETURN_BOOL(CefBrowserHost::CreateBrowser(args.window_info, args.client, args.url, args.settings,
                                            args.extra_info, args.request_context));
}

/**
 * CefBrowserHost::CreateBrowserSync(WindowInfo $windowInfo, ?Client $client, string $url, BrowserSettings $settings,
 * ?DictionaryValue $extraInfo, ?RequestContext $requestContext): ?Browser
 *
 * Create a browser and return it before the call returns.
 * @return The browser CefBrowserHost::CreateBrowserSync() returns, or null if it returns none.
 * @throws Error if CEF is not running.
 * @throws ArgumentCountError if the number of arguments is not six.
 * @throws TypeError if an argument has the wrong type.
 */
ZEND_METHOD(CefBrowserHost, CreateBrowserSync) {
  if (!require_running()) {
    RETURN_THROWS();
  }
  BrowserArgs args;
  if (!parse_browser_args(INTERNAL_FUNCTION_PARAM_PASSTHRU, &args)) {
    RETURN_THROWS();
  }
  CefRefPtr<CefBrowser> browser = CefBrowserHost::CreateBrowserSync(
      args.window_info, args.client, args.url, args.settings, args.extra_info, args.request_context);
  cl_wrap_CefBrowser(return_value, browser.get());
}
