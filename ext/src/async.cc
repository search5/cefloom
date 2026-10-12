/**
 * @file
 * @brief Handlers that CEF calls on its IO thread and that must not make that thread wait for PHP.
 *
 * A hang was observed (llm-wiki, verified-findings F14) in which the IO thread waited for the PHP thread inside a
 * handler while the PHP thread sat in CefDoMessageLoopWork() on a synchronous call to the GPU process (call stack of one
 * hang). Why the IO thread could not answer that call is an interpretation that was not checked in the CEF sources.
 * Everything here therefore answers CEF at once, runs PHP later on the PHP thread, and passes the result on through the
 * callbacks CEF offers for this.
 *
 * Written by hand: CefResourceHandler and CefSchemeHandlerFactory (tools/gen/scope.py CUSTOM_PROXY), and the functions in
 * namespace cl_async that the generated proxies call (ASYNC_METHODS). The PHP side is unchanged.
 */
#include "cl_gen.h"

namespace {

// ---- running PHP later ---------------------------------------------------------------------------------------

/** CefTask that runs a function on the PHP thread, or a fallback if PHP is gone when it comes up. */
class PhpTask : public CefTask {
 public:
  /** Create a task that runs |run| if PHP is alive when it executes, else |dropped| (may be empty). */
  PhpTask(std::function<void()> run, std::function<void()> dropped) : run_(std::move(run)), dropped_(std::move(dropped)) {}
  /** Run the function or the fallback. Thread: CEF UI (the PHP thread). */
  void Execute() override {
    if (cl::g_php_alive) {
      run_();
    } else if (dropped_) {
      dropped_();
    }
  }

 private:
  /** Function to run on the PHP thread. */
  std::function<void()> run_;
  /** Function to run instead if PHP is gone; may be empty. */
  std::function<void()> dropped_;
  /** Thread-safe reference counting of CEF. */
  IMPLEMENT_REFCOUNTING(PhpTask);
};

/**
 * Queue |run| for the PHP thread without waiting. |dropped| runs instead if PHP is gone by then.
 * @return false, and runs neither, if the task could not be queued (PHP or CEF not running).
 * @note Thread: any.
 */
bool post_php(std::function<void()> run, std::function<void()> dropped = nullptr) {
  if (!cl::g_php_alive || !cl::g_cef_running) {
    return false;
  }
  return CefPostTask(TID_UI, new PhpTask(std::move(run), std::move(dropped)));
}

/**
 * Call the PHP method |fn| of |obj| with |n| arguments in |args|, then invalidate the Buffers among them and free them.
 * The result stays in |rv| (UNDEF if the call failed); the caller releases it with release().
 * @return false if the call did not run or threw (see cl::call_method()).
 * @note Thread: PHP.
 */
bool call_hook(zend_object* obj, zend_function* fn, zval* args, uint32_t n, zval* rv) {
  ZVAL_UNDEF(rv);
  bool ok = cl::call_method(obj, fn, n, args, rv);
  for (uint32_t i = 0; i < n; ++i) {
    cl::invalidate_buffers(&args[i]);
    zval_ptr_dtor(&args[i]);
  }
  return ok;
}

/** Free the result |rv| of call_hook() unless it is UNDEF. */
void release(zval* rv) {
  if (Z_TYPE_P(rv) != IS_UNDEF) {
    zval_ptr_dtor(rv);
  }
}

/** Result code of a failed read or skip, as in CEF's ERR_FAILED. */
constexpr int kErrFailed = -2;

// ---- a CefCallback handed to PHP -----------------------------------------------------------------------------

/**
 * CefCallback handed to PHP in place of the one of CEF. The first Continue() or Cancel() wins.
 * |before_continue| runs on the PHP thread right before Continue() is forwarded (the response headers are asked for then).
 */
class StagedCallback : public CefCallback {
 public:
  /** Wrap |real|; |before_continue| (may be empty) runs before a forwarded Continue(). */
  StagedCallback(CefRefPtr<CefCallback> real, std::function<void()> before_continue)
      : real_(std::move(real)), before_continue_(std::move(before_continue)) {}

  /** Run the before-continue function and forward Continue() unless already done. Thread: PHP. */
  void Continue() override {
    if (done_.exchange(true)) {
      return;
    }
    if (before_continue_) {
      before_continue_();
    }
    real_->Continue();
  }
  /** Forward Cancel() unless already done. Thread: PHP. */
  void Cancel() override {
    if (!done_.exchange(true)) {
      real_->Cancel();
    }
  }

 private:
  /** The callback of CEF. */
  CefRefPtr<CefCallback> real_;
  /** Function to run before Continue() is forwarded; may be empty. */
  std::function<void()> before_continue_;
  /** Set by the first Continue() or Cancel(). */
  std::atomic<bool> done_{false};
  /** Thread-safe reference counting of CEF. */
  IMPLEMENT_REFCOUNTING(StagedCallback);
};

// ---- CefResourceHandler ----------------------------------------------------------------------------------------

/** Indexes of the methods of a PHP ResourceHandler that AsyncResourceHandler may call; kHookCount is their number. */
enum { kOpen, kProcessRequest, kGetResponseHeaders, kSkip, kRead, kReadResponse, kCancel, kHookCount };
/** Lowercase names of the PHP methods, in the order of the enum above. */
const char* const kResourceHooks[kHookCount] = {"open", "processrequest", "getresponseheaders", "skip",
                                                "read", "readresponse", "cancel"};

/**
 * CefResourceHandler that stands for a PHP ResourceHandler.
 * CEF calls the overrides on the IO thread; none of them waits for PHP, except the deprecated ReadResponse(). The PHP
 * methods run later on the PHP thread. An unoverridden PHP method behaves as in CEF's default handler.
 */
class AsyncResourceHandler : public CefResourceHandler, public cl::ProxyBase {
 public:
  /** Create the proxy of PHP object |o| and find which handler methods its class overrides. */
  explicit AsyncResourceHandler(zend_object* o) : cl::ProxyBase(o) {
    register_as(static_cast<CefResourceHandler*>(this));
    for (size_t i = 0; i < kHookCount; ++i) {
      hooks_[i] = cl::find_override(o->ce, ce_CefResourceHandler, kResourceHooks[i]);
    }
  }

  // CEF calls these on the IO thread. None of them waits for PHP.

  /**
   * Queue run_open() for PHP and answer CEF at once (handle_request false: the callback decides later).
   * @return true if queued; false with handle_request true if PHP is gone, so CEF cancels the request.
   * @note Thread: CEF IO.
   */
  bool Open(CefRefPtr<CefRequest> request, bool& handle_request, CefRefPtr<CefCallback> callback) override {
    if (!hooks_[kOpen]) {
      return CefResourceHandler::Open(request, handle_request, callback);  // CEF then tries ProcessRequest
    }
    handle_request = false;
    CefRefPtr<AsyncResourceHandler> self(this);
    if (!post_php([self, request, callback]() { self->run_open(request, callback); },
                  [callback]() { callback->Cancel(); })) {
      handle_request = true;
      return false;
    }
    return true;
  }

  /**
   * Queue run_process_request() for PHP.
   * @return true if queued; false if PHP does not override it or is gone.
   * @note Thread: CEF IO.
   */
  bool ProcessRequest(CefRefPtr<CefRequest> request, CefRefPtr<CefCallback> callback) override {
    if (!hooks_[kProcessRequest]) {
      return false;
    }
    CefRefPtr<AsyncResourceHandler> self(this);
    return post_php([self, request, callback]() { self->run_process_request(request, callback); },
                    [callback]() { callback->Cancel(); });
  }

  /**
   * Answer from the headers that prepare_headers() fetched from PHP. Sets ERR_FAILED if there are none.
   * @note Thread: CEF IO. Never calls PHP.
   */
  void GetResponseHeaders(CefRefPtr<CefResponse> response, int64_t& response_length, CefString& redirectUrl) override {
    std::lock_guard<std::mutex> lock(mutex_);
    if (!prepared_) {
      response->SetError(ERR_FAILED);
      return;
    }
    response->SetError(prepared_->GetError());
    response->SetStatus(prepared_->GetStatus());
    response->SetStatusText(prepared_->GetStatusText());
    response->SetMimeType(prepared_->GetMimeType());
    response->SetCharset(prepared_->GetCharset());
    CefResponse::HeaderMap headers;
    prepared_->GetHeaderMap(headers);
    response->SetHeaderMap(headers);
    response_length = length_;
    redirectUrl = redirect_;
  }

  /**
   * Queue run_skip() for PHP.
   * @return true if queued; false with |bytes_skipped| set to an error if PHP is gone.
   * @note Thread: CEF IO.
   */
  bool Skip(int64_t bytes_to_skip, int64_t& bytes_skipped, CefRefPtr<CefResourceSkipCallback> callback) override {
    if (!hooks_[kSkip]) {
      return CefResourceHandler::Skip(bytes_to_skip, bytes_skipped, callback);
    }
    bytes_skipped = 0;
    CefRefPtr<AsyncResourceHandler> self(this);
    if (!post_php([self, bytes_to_skip, callback]() { self->run_skip(bytes_to_skip, callback); },
                  [callback]() { callback->Continue(kErrFailed); })) {
      bytes_skipped = kErrFailed;
      return false;
    }
    return true;
  }

  /**
   * Queue run_read() for PHP.
   * @return true if queued; false with |bytes_read| set to an error if PHP is gone.
   * @note Thread: CEF IO.
   */
  bool Read(void* data_out, int bytes_to_read, int& bytes_read, CefRefPtr<CefResourceReadCallback> callback) override {
    if (!hooks_[kRead]) {
      return CefResourceHandler::Read(data_out, bytes_to_read, bytes_read, callback);  // CEF then tries ReadResponse
    }
    bytes_read = 0;
    CefRefPtr<AsyncResourceHandler> self(this);
    if (!post_php([self, data_out, bytes_to_read, callback]() { self->run_read(data_out, bytes_to_read, callback); },
                  [callback]() { callback->Continue(kErrFailed); })) {
      bytes_read = kErrFailed;
      return false;
    }
    return true;
  }

  /**
   * Call the deprecated ReadResponse() of PHP and wait for it. Kept as it was: it waits for PHP.
   * @note Thread: CEF IO; waits on the PHP thread through cl::run_on_php_thread() (F14 timeout fallback applies).
   */
  bool ReadResponse(void* data_out, int bytes_to_read, int& bytes_read, CefRefPtr<CefCallback> callback) override {
    zend_function* fn = hooks_[kReadResponse];
    if (!fn) {
      return CefResourceHandler::ReadResponse(data_out, bytes_to_read, bytes_read, callback);
    }
    bool result = false;
    int read = bytes_read;
    cl::run_on_php_thread([&]() {
      zval args[2];
      cl::buffer_new(&args[0], data_out, static_cast<size_t>(bytes_to_read), true);
      cl_wrap_CefCallback(&args[1], callback.get());
      zval rv;
      if (call_hook(object(), fn, args, 2, &rv)) {
        if (zval* r = cl::result_at(&rv, 2, 0)) {
          result = zend_is_true(r);
        }
        if (zval* r = cl::result_at(&rv, 2, 1)) {
          read = static_cast<int>(zval_get_long(r));
        }
      }
      release(&rv);
    });
    bytes_read = read;
    return result;
  }

  /** Queue the PHP cancel() method. Thread: CEF IO. */
  void Cancel() override {
    if (!hooks_[kCancel]) {
      return;
    }
    CefRefPtr<AsyncResourceHandler> self(this);
    post_php([self]() {
      zval rv;
      call_hook(self->object(), self->hooks_[kCancel], nullptr, 0, &rv);
      release(&rv);
    });
  }

 private:
  // ---- on the PHP thread

  /**
   * Call the PHP open(). Cancel the request if PHP declines; continue at once if it handled the request itself; else PHP
   * continues or cancels later through the callback it got.
   */
  void run_open(CefRefPtr<CefRequest> request, CefRefPtr<CefCallback> callback) {
    CefRefPtr<AsyncResourceHandler> self(this);
    CefRefPtr<StagedCallback> staged = new StagedCallback(callback, [self]() { self->prepare_headers(); });
    bool handled = false;
    bool handle_request = false;
    zval args[2];
    cl_wrap_CefRequest(&args[0], request.get());
    cl_wrap_CefCallback(&args[1], staged.get());
    zval rv;
    if (call_hook(object(), hooks_[kOpen], args, 2, &rv)) {
      if (zval* r = cl::result_at(&rv, 2, 0)) {
        handled = zend_is_true(r);
      }
      if (zval* r = cl::result_at(&rv, 2, 1)) {
        handle_request = zend_is_true(r);
      }
    }
    release(&rv);
    if (!handled) {
      staged->Cancel();
    } else if (handle_request) {
      staged->Continue();
    }  // else PHP continues or cancels later through the callback it got
  }

  /** Call the PHP processRequest(). Cancel the request unless PHP returned true. */
  void run_process_request(CefRefPtr<CefRequest> request, CefRefPtr<CefCallback> callback) {
    CefRefPtr<AsyncResourceHandler> self(this);
    CefRefPtr<StagedCallback> staged = new StagedCallback(callback, [self]() { self->prepare_headers(); });
    bool handled = false;
    zval args[2];
    cl_wrap_CefRequest(&args[0], request.get());
    cl_wrap_CefCallback(&args[1], staged.get());
    zval rv;
    if (call_hook(object(), hooks_[kProcessRequest], args, 2, &rv)) {
      if (zval* r = cl::result_at(&rv, 1, 0)) {
        handled = zend_is_true(r);
      }
    }
    release(&rv);
    if (!handled) {
      staged->Cancel();
    }
  }

  /**
   * Fetch the response headers from PHP now, so that GetResponseHeaders() on the IO thread can answer from memory.
   * Stores the response, length and redirect URL under the mutex; the response has ERR_FAILED if the call failed.
   */
  void prepare_headers() {
    CefRefPtr<CefResponse> response = CefResponse::Create();
    int64_t length = -1;
    CefString redirect;
    bool ok = false;
    zval args[1];
    cl_wrap_CefResponse(&args[0], response.get());
    zval rv;
    if (call_hook(object(), hooks_[kGetResponseHeaders], args, 1, &rv)) {
      ok = true;
      if (zval* r = cl::result_at(&rv, 2, 0)) {
        length = static_cast<int64_t>(zval_get_long(r));
      }
      if (zval* r = cl::result_at(&rv, 2, 1)) {
        redirect = cl::to_cef(r);
      }
    }
    release(&rv);
    if (!ok) {
      response->SetError(ERR_FAILED);
    }
    std::lock_guard<std::mutex> lock(mutex_);
    prepared_ = response;
    length_ = length;
    redirect_ = redirect;
  }

  /**
   * Call the PHP skip() and complete |callback| unless PHP will do so later.
   * Completes with the bytes skipped, or an error if the call failed.
   */
  void run_skip(int64_t bytes_to_skip, CefRefPtr<CefResourceSkipCallback> callback) {
    bool ok = false;
    int64_t skipped = kErrFailed;
    zval args[2];
    ZVAL_LONG(&args[0], static_cast<zend_long>(bytes_to_skip));
    cl_wrap_CefResourceSkipCallback(&args[1], callback.get());
    zval rv;
    if (call_hook(object(), hooks_[kSkip], args, 2, &rv)) {
      if (zval* r = cl::result_at(&rv, 2, 0)) {
        ok = zend_is_true(r);
      }
      if (zval* r = cl::result_at(&rv, 2, 1)) {
        skipped = static_cast<int64_t>(zval_get_long(r));
      }
    }
    release(&rv);
    if (ok && skipped == 0) {
      return;  // PHP continues through the callback later
    }
    callback->Continue(ok && skipped > 0 ? skipped : (skipped < 0 ? skipped : kErrFailed));
  }

  /**
   * Call the PHP read() and complete |callback| unless PHP will do so later.
   * The count is clamped to |bytes_to_read|; 0 ends the response, a negative value is an error.
   */
  void run_read(void* data_out, int bytes_to_read, CefRefPtr<CefResourceReadCallback> callback) {
    bool ok = false;
    int read = kErrFailed;
    zval args[2];
    cl::buffer_new(&args[0], data_out, static_cast<size_t>(bytes_to_read), true);
    cl_wrap_CefResourceReadCallback(&args[1], callback.get());
    zval rv;
    if (call_hook(object(), hooks_[kRead], args, 2, &rv)) {
      if (zval* r = cl::result_at(&rv, 2, 0)) {
        ok = zend_is_true(r);
      }
      read = 0;
      if (zval* r = cl::result_at(&rv, 2, 1)) {
        read = static_cast<int>(zval_get_long(r));
      }
    }
    release(&rv);
    if (read > bytes_to_read) {
      read = bytes_to_read;
    }
    if (ok && read == 0) {
      return;  // PHP continues through the callback later
    }
    callback->Continue(read);  // > 0: data, 0: the response is complete, < 0: an error
  }

  /** Overriding PHP methods by hook index; null where not overridden. */
  zend_function* hooks_[kHookCount] = {};
  /** Guards prepared_, length_ and redirect_ (written on the PHP thread, read on the IO thread). */
  std::mutex mutex_;
  /** Response headers fetched by prepare_headers(), or null before. */
  CefRefPtr<CefResponse> prepared_;
  /** Response length from PHP, -1 if unknown. */
  int64_t length_ = -1;
  /** Redirect URL from PHP, empty for none. */
  CefString redirect_;
  /** Thread-safe reference counting of CEF. */
  IMPLEMENT_REFCOUNTING(AsyncResourceHandler);
  /** Copying is disallowed. */
  DISALLOW_COPY_AND_ASSIGN(AsyncResourceHandler);
};

// ---- CefSchemeHandlerFactory -----------------------------------------------------------------------------------

class AsyncSchemeFactory;

/**
 * Handler a scheme factory returns at once; asks the PHP factory for the real handler when CEF opens the resource.
 * Everything after Open() is passed to that handler.
 */
class DeferredResourceHandler : public CefResourceHandler {
 public:
  /**
   * Keep what the PHP factory needs to make the handler: |factory| (an AsyncSchemeFactory), the browser, frame and scheme.
   */
  DeferredResourceHandler(CefRefPtr<CefSchemeHandlerFactory> factory, CefRefPtr<CefBrowser> browser, CefRefPtr<CefFrame> frame,
                          const CefString& scheme)
      : factory_(std::move(factory)), browser_(std::move(browser)), frame_(std::move(frame)), scheme_(scheme) {}

  /**
   * Queue run_open() for PHP and answer CEF at once.
   * @return true if queued; false with handle_request true if PHP is gone.
   * @note Thread: CEF IO.
   */
  bool Open(CefRefPtr<CefRequest> request, bool& handle_request, CefRefPtr<CefCallback> callback) override;

  /** Return false: Open() is always used instead. */
  bool ProcessRequest(CefRefPtr<CefRequest> request, CefRefPtr<CefCallback> callback) override { return false; }

  /** Forward to the handler PHP made; set ERR_FAILED if there is none. Thread: CEF IO. */
  void GetResponseHeaders(CefRefPtr<CefResponse> response, int64_t& response_length, CefString& redirectUrl) override {
    CefRefPtr<CefResourceHandler> inner = inner_handler();
    if (inner) {
      inner->GetResponseHeaders(response, response_length, redirectUrl);
    } else {
      response->SetError(ERR_FAILED);
    }
  }

  /** Forward to the handler PHP made; fail with an error if there is none. Thread: CEF IO. */
  bool Skip(int64_t bytes_to_skip, int64_t& bytes_skipped, CefRefPtr<CefResourceSkipCallback> callback) override {
    CefRefPtr<CefResourceHandler> inner = inner_handler();
    if (!inner) {
      bytes_skipped = kErrFailed;
      return false;
    }
    return inner->Skip(bytes_to_skip, bytes_skipped, callback);
  }

  /** Forward to the handler PHP made; fail with an error if there is none. Thread: CEF IO. */
  bool Read(void* data_out, int bytes_to_read, int& bytes_read, CefRefPtr<CefResourceReadCallback> callback) override {
    CefRefPtr<CefResourceHandler> inner = inner_handler();
    if (!inner) {
      bytes_read = kErrFailed;
      return false;
    }
    return inner->Read(data_out, bytes_to_read, bytes_read, callback);
  }

  /** Forward to the handler PHP made; fail with an error if there is none. Thread: CEF IO. */
  bool ReadResponse(void* data_out, int bytes_to_read, int& bytes_read, CefRefPtr<CefCallback> callback) override {
    CefRefPtr<CefResourceHandler> inner = inner_handler();
    if (!inner) {
      bytes_read = kErrFailed;
      return false;
    }
    return inner->ReadResponse(data_out, bytes_to_read, bytes_read, callback);
  }

  /** Forward to the handler PHP made, if any. Thread: CEF IO. */
  void Cancel() override {
    CefRefPtr<CefResourceHandler> inner = inner_handler();
    if (inner) {
      inner->Cancel();
    }
  }

 private:
  /** Ask the PHP factory for the handler and open the request with it. Thread: PHP. */
  void run_open(CefRefPtr<CefRequest> request, CefRefPtr<CefCallback> callback);

  /** Return the handler PHP made, or null while there is none. Thread: any. */
  CefRefPtr<CefResourceHandler> inner_handler() {
    std::lock_guard<std::mutex> lock(mutex_);
    return inner_;
  }

  /** The AsyncSchemeFactory that makes the handler. */
  CefRefPtr<CefSchemeHandlerFactory> factory_;  // an AsyncSchemeFactory
  /** Browser of the request, passed to the PHP factory. */
  CefRefPtr<CefBrowser> browser_;
  /** Frame of the request, passed to the PHP factory. */
  CefRefPtr<CefFrame> frame_;
  /** Scheme name of the request, passed to the PHP factory. */
  CefString scheme_;
  /** Guards inner_. */
  std::mutex mutex_;
  /** The handler the PHP factory made; null until run_open() has stored it. */
  CefRefPtr<CefResourceHandler> inner_;
  /** Thread-safe reference counting of CEF. */
  IMPLEMENT_REFCOUNTING(DeferredResourceHandler);
  /** Copying is disallowed. */
  DISALLOW_COPY_AND_ASSIGN(DeferredResourceHandler);
};

/** CefSchemeHandlerFactory that stands for a PHP SchemeHandlerFactory; hands CEF a DeferredResourceHandler at once. */
class AsyncSchemeFactory : public CefSchemeHandlerFactory, public cl::ProxyBase {
 public:
  /** Create the proxy of PHP object |o| and find its create() method. */
  explicit AsyncSchemeFactory(zend_object* o) : cl::ProxyBase(o) {
    register_as(static_cast<CefSchemeHandlerFactory*>(this));
    hook_ = cl::find_override(o->ce, ce_CefSchemeHandlerFactory, "create");
  }

  /**
   * Return a DeferredResourceHandler, or null if the PHP class does not override create().
   * @note Thread: CEF IO. Never calls PHP.
   */
  CefRefPtr<CefResourceHandler> Create(CefRefPtr<CefBrowser> browser, CefRefPtr<CefFrame> frame,
                                       const CefString& scheme_name, CefRefPtr<CefRequest> request) override {
    if (!hook_) {
      return nullptr;
    }
    return new DeferredResourceHandler(this, browser, frame, scheme_name);
  }

  /**
   * Return the handler the PHP create() makes for this request, or null if it makes none.
   * @note Thread: PHP.
   */
  CefRefPtr<CefResourceHandler> make_handler(CefRefPtr<CefBrowser> browser, CefRefPtr<CefFrame> frame,
                                             const CefString& scheme, CefRefPtr<CefRequest> request) {
    CefRefPtr<CefResourceHandler> handler;
    zval args[4];
    cl_wrap_CefBrowser(&args[0], browser.get());
    cl_wrap_CefFrame(&args[1], frame.get());
    cl::ret_str(&args[2], scheme);
    cl_wrap_CefRequest(&args[3], request.get());
    zval rv;
    if (call_hook(object(), hook_, args, 4, &rv)) {
      if (zval* r = cl::result_at(&rv, 1, 0)) {
        if (Z_TYPE_P(r) == IS_OBJECT && instanceof_function(Z_OBJCE_P(r), ce_CefResourceHandler)) {
          handler = cl_make_CefResourceHandler(Z_OBJ_P(r));
        }
      }
    }
    release(&rv);
    return handler;
  }

 private:
  /** The overriding PHP create() method, or null. */
  zend_function* hook_ = nullptr;
  /** Thread-safe reference counting of CEF. */
  IMPLEMENT_REFCOUNTING(AsyncSchemeFactory);
  /** Copying is disallowed. */
  DISALLOW_COPY_AND_ASSIGN(AsyncSchemeFactory);
};

/** Queue run_open() for PHP; see the declaration. */
bool DeferredResourceHandler::Open(CefRefPtr<CefRequest> request, bool& handle_request, CefRefPtr<CefCallback> callback) {
  handle_request = false;
  CefRefPtr<DeferredResourceHandler> self(this);
  if (!post_php([self, request, callback]() { self->run_open(request, callback); },
                [callback]() { callback->Cancel(); })) {
    handle_request = true;
    return false;
  }
  return true;
}

/**
 * Make the real handler on the PHP thread, store it, then open the request with it as if CEF had been given it directly.
 * Cancels the request if the PHP factory makes no handler.
 */
void DeferredResourceHandler::run_open(CefRefPtr<CefRequest> request, CefRefPtr<CefCallback> callback) {
  CefRefPtr<CefResourceHandler> inner =
      static_cast<AsyncSchemeFactory*>(factory_.get())->make_handler(browser_, frame_, scheme_, request);
  if (!inner) {
    callback->Cancel();
    return;
  }
  {
    std::lock_guard<std::mutex> lock(mutex_);
    inner_ = inner;
  }
  // From here the handler the PHP factory made answers, exactly as if CEF had been given it directly.
  bool handle_request = false;
  bool result = inner->Open(request, handle_request, callback);
  if (handle_request) {
    if (result) {
      callback->Continue();
    } else {
      callback->Cancel();
    }
  } else if (!result && !inner->ProcessRequest(request, callback)) {
    callback->Cancel();
  }
}

}  // namespace

/** Return the proxy of the PHP ResourceHandler |o|. Thread: PHP. */
CefRefPtr<CefResourceHandler> cl_make_CefResourceHandler(zend_object* o) { return new AsyncResourceHandler(o); }

/** Return the proxy of the PHP SchemeHandlerFactory |o|. Thread: PHP. */
CefRefPtr<CefSchemeHandlerFactory> cl_make_CefSchemeHandlerFactory(zend_object* o) {
  return new AsyncSchemeFactory(o);
}

// ---- the generated proxies call these for their asynchronous methods ------------------------------------------------

namespace cl_async {

/**
 * Queue the PHP onBeforeResourceLoad() and let the request wait for its verdict.
 * The verdict (a cef_return_value_t) is applied to |callback| on the PHP thread; RV_CONTINUE_ASYNC leaves it to PHP.
 * @return RV_CONTINUE_ASYNC if queued, else RV_CONTINUE (PHP gone).
 * @note Thread: CEF IO.
 */
cef_return_value_t CefResourceRequestHandler_OnBeforeResourceLoad(CefRefPtr<CefResourceRequestHandler> self,
                                                                  zend_object* object, zend_function* fn,
                                                                  CefRefPtr<CefBrowser> browser, CefRefPtr<CefFrame> frame,
                                                                  CefRefPtr<CefRequest> request,
                                                                  CefRefPtr<CefCallback> callback) {
  bool queued = post_php(
      [self, object, fn, browser, frame, request, callback]() {
        zval args[4];
        cl_wrap_CefBrowser(&args[0], browser.get());
        cl_wrap_CefFrame(&args[1], frame.get());
        cl_wrap_CefRequest(&args[2], request.get());
        cl_wrap_CefCallback(&args[3], callback.get());
        cef_return_value_t verdict = RV_CONTINUE;
        zval rv;
        if (call_hook(object, fn, args, 4, &rv)) {
          if (zval* r = cl::result_at(&rv, 1, 0)) {
            verdict = static_cast<cef_return_value_t>(zval_get_long(r));
          }
        }
        release(&rv);
        if (verdict == RV_CANCEL) {
          callback->Cancel();
        } else if (verdict == RV_CONTINUE) {
          callback->Continue();
        }  // RV_CONTINUE_ASYNC: PHP calls the callback later
      },
      [callback]() { callback->Cancel(); });
  return queued ? RV_CONTINUE_ASYNC : RV_CONTINUE;
}

/** Queue the PHP onResourceLoadComplete(). Thread: CEF IO. Dropped if PHP is gone. */
void CefResourceRequestHandler_OnResourceLoadComplete(CefRefPtr<CefResourceRequestHandler> self, zend_object* object,
                                                      zend_function* fn, CefRefPtr<CefBrowser> browser,
                                                      CefRefPtr<CefFrame> frame, CefRefPtr<CefRequest> request,
                                                      CefRefPtr<CefResponse> response, cef_urlrequest_status_t status,
                                                      int64_t received_content_length) {
  post_php([self, object, fn, browser, frame, request, response, status, received_content_length]() {
    zval args[6];
    cl_wrap_CefBrowser(&args[0], browser.get());
    cl_wrap_CefFrame(&args[1], frame.get());
    cl_wrap_CefRequest(&args[2], request.get());
    cl_wrap_CefResponse(&args[3], response.get());
    ZVAL_LONG(&args[4], static_cast<zend_long>(status));
    ZVAL_LONG(&args[5], static_cast<zend_long>(received_content_length));
    zval rv;
    call_hook(object, fn, args, 6, &rv);
    release(&rv);
  });
}

/**
 * Queue the PHP getAuthCredentials() and announce the request as handled.
 * PHP answers through the auth callback; if it declines or PHP is gone the callback is cancelled.
 * @return true if queued, else false (CEF's default).
 * @note Thread: CEF IO.
 */
bool CefRequestHandler_GetAuthCredentials(CefRefPtr<CefRequestHandler> self, zend_object* object, zend_function* fn,
                                          CefRefPtr<CefBrowser> browser, const CefString& origin_url, bool isProxy,
                                          const CefString& host, int port, const CefString& realm,
                                          const CefString& scheme, CefRefPtr<CefAuthCallback> callback) {
  CefString origin = origin_url, host_copy = host, realm_copy = realm, scheme_copy = scheme;
  return post_php(
      [self, object, fn, browser, origin, isProxy, host_copy, port, realm_copy, scheme_copy, callback]() {
        zval args[8];
        cl_wrap_CefBrowser(&args[0], browser.get());
        cl::ret_str(&args[1], origin);
        ZVAL_BOOL(&args[2], isProxy);
        cl::ret_str(&args[3], host_copy);
        ZVAL_LONG(&args[4], static_cast<zend_long>(port));
        cl::ret_str(&args[5], realm_copy);
        cl::ret_str(&args[6], scheme_copy);
        cl_wrap_CefAuthCallback(&args[7], callback.get());
        bool handled = false;
        zval rv;
        if (call_hook(object, fn, args, 8, &rv)) {
          if (zval* r = cl::result_at(&rv, 1, 0)) {
            handled = zend_is_true(r);
          }
        }
        release(&rv);
        if (!handled) {
          callback->Cancel();  // the request was already announced as handled: PHP declined, so it fails
        }
      },
      [callback]() { callback->Cancel(); });
}

/** Queue the PHP onAudioStreamStarted() with a copy of |params|. Thread: CEF audio capture thread. Dropped if PHP is gone. */
void CefAudioHandler_OnAudioStreamStarted(CefRefPtr<CefAudioHandler> self, zend_object* object, zend_function* fn,
                                          CefRefPtr<CefBrowser> browser, const CefAudioParameters& params, int channels) {
  CefAudioParameters copy = params;
  post_php([self, object, fn, browser, copy, channels]() {
    zval args[3];
    cl_wrap_CefBrowser(&args[0], browser.get());
    cl_to_zval_cef_audio_parameters_t(&args[1], copy);
    ZVAL_LONG(&args[2], static_cast<zend_long>(channels));
    zval rv;
    call_hook(object, fn, args, 3, &rv);
    release(&rv);
  });
}

/**
 * Copy the packet and queue the PHP onAudioStreamPacket(), with one read-only Buffer per channel valid for that call.
 * Packets are dropped while 200 are waiting for PHP.
 * @note Thread: CEF audio stream thread. Never waits for PHP.
 */
void CefAudioHandler_OnAudioStreamPacket(CefRefPtr<CefAudioHandler> self, zend_object* object, zend_function* fn,
                                         CefRefPtr<CefBrowser> browser, const float** data, int frames, int64_t pts,
                                         int channels) {
  // PHP gets a packet some time later, and a PHP script that does not pump for a while must not make the packets pile up
  // without end: the ones that come while many are waiting are dropped.
  static std::atomic<int> pending{0};
  constexpr int kMaxPending = 200;
  if (channels <= 0 || frames <= 0 || pending.load() >= kMaxPending) {
    return;
  }
  auto samples = std::make_shared<std::vector<float>>(static_cast<size_t>(channels) * frames);
  for (int c = 0; c < channels; ++c) {
    std::copy(data[c], data[c] + frames, samples->begin() + static_cast<size_t>(c) * frames);
  }
  ++pending;
  bool queued = post_php(
      [self, object, fn, browser, samples, channels, frames, pts]() {
        --pending;
        zval args[3];
        cl_wrap_CefBrowser(&args[0], browser.get());
        array_init(&args[1]);
        for (int c = 0; c < channels; ++c) {
          zval b;
          cl::buffer_new(&b, samples->data() + static_cast<size_t>(c) * frames, static_cast<size_t>(frames) * sizeof(float), false);
          add_next_index_zval(&args[1], &b);
        }
        ZVAL_LONG(&args[2], static_cast<zend_long>(pts));
        zval rv;
        call_hook(object, fn, args, 3, &rv);
        release(&rv);
      },
      [] { --pending; });
  if (!queued) {
    --pending;
  }
}

/** Queue the PHP onAudioStreamError() with a copy of |message|. Thread: CEF UI (stream creation) or audio stream thread (capturing). Dropped if PHP is gone. */
void CefAudioHandler_OnAudioStreamError(CefRefPtr<CefAudioHandler> self, zend_object* object, zend_function* fn,
                                        CefRefPtr<CefBrowser> browser, const CefString& message) {
  CefString text = message;
  post_php([self, object, fn, browser, text]() {
    zval args[2];
    cl_wrap_CefBrowser(&args[0], browser.get());
    cl::ret_str(&args[1], text);
    zval rv;
    call_hook(object, fn, args, 2, &rv);
    release(&rv);
  });
}

}  // namespace cl_async
