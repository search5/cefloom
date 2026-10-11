// Handlers that CEF calls on its IO thread and that must not make that thread wait for PHP.
//
// The IO thread of the browser process also carries its IPC. While it waits for the PHP thread, the PHP thread can
// wait inside CefDoMessageLoopWork() for an IPC reply (the compositor asks the GPU process synchronously), and
// neither goes on (llm-wiki, verified-findings F14). Everything here therefore answers CEF at once, runs PHP later
// on the PHP thread, and passes the result on through the callbacks CEF offers for this.
//
// Written by hand: CefResourceHandler and CefSchemeHandlerFactory (tools/gen/scope.py CUSTOM_PROXY), and the
// functions in namespace cl_async that the generated proxies call (ASYNC_METHODS). The PHP side is unchanged.
#include "cl_gen.h"

namespace {

// ---- running PHP later ---------------------------------------------------------------------------------------

class PhpTask : public CefTask {
 public:
  PhpTask(std::function<void()> run, std::function<void()> dropped) : run_(std::move(run)), dropped_(std::move(dropped)) {}
  void Execute() override {
    if (cl::g_php_alive) {
      run_();
    } else if (dropped_) {
      dropped_();
    }
  }

 private:
  std::function<void()> run_;
  std::function<void()> dropped_;
  IMPLEMENT_REFCOUNTING(PhpTask);
};

// Queue |run| for the PHP thread without waiting. |dropped| runs instead if PHP is gone by then. Returns false (and
// runs neither) if the task could not be queued.
bool post_php(std::function<void()> run, std::function<void()> dropped = nullptr) {
  if (!cl::g_php_alive || !cl::g_cef_running) {
    return false;
  }
  return CefPostTask(TID_UI, new PhpTask(std::move(run), std::move(dropped)));
}

// Call a PHP method. Consumes |args|. The result stays in |rv| (the caller releases it).
bool call_hook(zend_object* obj, zend_function* fn, zval* args, uint32_t n, zval* rv) {
  ZVAL_UNDEF(rv);
  bool ok = cl::call_method(obj, fn, n, args, rv);
  for (uint32_t i = 0; i < n; ++i) {
    cl::invalidate_buffers(&args[i]);
    zval_ptr_dtor(&args[i]);
  }
  return ok;
}

void release(zval* rv) {
  if (Z_TYPE_P(rv) != IS_UNDEF) {
    zval_ptr_dtor(rv);
  }
}

constexpr int kErrFailed = -2;

// ---- a CefCallback handed to PHP -----------------------------------------------------------------------------

// Stands for the callback of CEF. The first Continue() or Cancel() wins. |before_continue| runs on the PHP thread
// right before Continue() is forwarded (the response headers are asked for then).
class StagedCallback : public CefCallback {
 public:
  StagedCallback(CefRefPtr<CefCallback> real, std::function<void()> before_continue)
      : real_(std::move(real)), before_continue_(std::move(before_continue)) {}

  void Continue() override {
    if (done_.exchange(true)) {
      return;
    }
    if (before_continue_) {
      before_continue_();
    }
    real_->Continue();
  }
  void Cancel() override {
    if (!done_.exchange(true)) {
      real_->Cancel();
    }
  }

 private:
  CefRefPtr<CefCallback> real_;
  std::function<void()> before_continue_;
  std::atomic<bool> done_{false};
  IMPLEMENT_REFCOUNTING(StagedCallback);
};

// ---- CefResourceHandler ----------------------------------------------------------------------------------------

enum { kOpen, kProcessRequest, kGetResponseHeaders, kSkip, kRead, kReadResponse, kCancel, kHookCount };
const char* const kResourceHooks[kHookCount] = {"open", "processrequest", "getresponseheaders", "skip",
                                                "read", "readresponse", "cancel"};

class AsyncResourceHandler : public CefResourceHandler, public cl::ProxyBase {
 public:
  explicit AsyncResourceHandler(zend_object* o) : cl::ProxyBase(o) {
    register_as(static_cast<CefResourceHandler*>(this));
    for (size_t i = 0; i < kHookCount; ++i) {
      hooks_[i] = cl::find_override(o->ce, ce_CefResourceHandler, kResourceHooks[i]);
    }
  }

  // CEF calls these on the IO thread. None of them waits for PHP.

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

  bool ProcessRequest(CefRefPtr<CefRequest> request, CefRefPtr<CefCallback> callback) override {
    if (!hooks_[kProcessRequest]) {
      return false;
    }
    CefRefPtr<AsyncResourceHandler> self(this);
    return post_php([self, request, callback]() { self->run_process_request(request, callback); },
                    [callback]() { callback->Cancel(); });
  }

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

  // The deprecated read. Kept as it was: it waits for PHP.
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

  // Ask PHP for the response headers now, so that GetResponseHeaders() on the IO thread can answer from memory.
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

  zend_function* hooks_[kHookCount] = {};
  std::mutex mutex_;
  CefRefPtr<CefResponse> prepared_;
  int64_t length_ = -1;
  CefString redirect_;
  IMPLEMENT_REFCOUNTING(AsyncResourceHandler);
  DISALLOW_COPY_AND_ASSIGN(AsyncResourceHandler);
};

// ---- CefSchemeHandlerFactory -----------------------------------------------------------------------------------

class AsyncSchemeFactory;

// What the factory returns at once. The PHP factory is asked for the real handler when CEF opens the resource.
class DeferredResourceHandler : public CefResourceHandler {
 public:
  DeferredResourceHandler(CefRefPtr<CefSchemeHandlerFactory> factory, CefRefPtr<CefBrowser> browser, CefRefPtr<CefFrame> frame,
                          const CefString& scheme)
      : factory_(std::move(factory)), browser_(std::move(browser)), frame_(std::move(frame)), scheme_(scheme) {}

  bool Open(CefRefPtr<CefRequest> request, bool& handle_request, CefRefPtr<CefCallback> callback) override;

  bool ProcessRequest(CefRefPtr<CefRequest> request, CefRefPtr<CefCallback> callback) override { return false; }

  void GetResponseHeaders(CefRefPtr<CefResponse> response, int64_t& response_length, CefString& redirectUrl) override {
    CefRefPtr<CefResourceHandler> inner = inner_handler();
    if (inner) {
      inner->GetResponseHeaders(response, response_length, redirectUrl);
    } else {
      response->SetError(ERR_FAILED);
    }
  }

  bool Skip(int64_t bytes_to_skip, int64_t& bytes_skipped, CefRefPtr<CefResourceSkipCallback> callback) override {
    CefRefPtr<CefResourceHandler> inner = inner_handler();
    if (!inner) {
      bytes_skipped = kErrFailed;
      return false;
    }
    return inner->Skip(bytes_to_skip, bytes_skipped, callback);
  }

  bool Read(void* data_out, int bytes_to_read, int& bytes_read, CefRefPtr<CefResourceReadCallback> callback) override {
    CefRefPtr<CefResourceHandler> inner = inner_handler();
    if (!inner) {
      bytes_read = kErrFailed;
      return false;
    }
    return inner->Read(data_out, bytes_to_read, bytes_read, callback);
  }

  bool ReadResponse(void* data_out, int bytes_to_read, int& bytes_read, CefRefPtr<CefCallback> callback) override {
    CefRefPtr<CefResourceHandler> inner = inner_handler();
    if (!inner) {
      bytes_read = kErrFailed;
      return false;
    }
    return inner->ReadResponse(data_out, bytes_to_read, bytes_read, callback);
  }

  void Cancel() override {
    CefRefPtr<CefResourceHandler> inner = inner_handler();
    if (inner) {
      inner->Cancel();
    }
  }

 private:
  void run_open(CefRefPtr<CefRequest> request, CefRefPtr<CefCallback> callback);

  CefRefPtr<CefResourceHandler> inner_handler() {
    std::lock_guard<std::mutex> lock(mutex_);
    return inner_;
  }

  CefRefPtr<CefSchemeHandlerFactory> factory_;  // an AsyncSchemeFactory
  CefRefPtr<CefBrowser> browser_;
  CefRefPtr<CefFrame> frame_;
  CefString scheme_;
  std::mutex mutex_;
  CefRefPtr<CefResourceHandler> inner_;
  IMPLEMENT_REFCOUNTING(DeferredResourceHandler);
  DISALLOW_COPY_AND_ASSIGN(DeferredResourceHandler);
};

class AsyncSchemeFactory : public CefSchemeHandlerFactory, public cl::ProxyBase {
 public:
  explicit AsyncSchemeFactory(zend_object* o) : cl::ProxyBase(o) {
    register_as(static_cast<CefSchemeHandlerFactory*>(this));
    hook_ = cl::find_override(o->ce, ce_CefSchemeHandlerFactory, "create");
  }

  CefRefPtr<CefResourceHandler> Create(CefRefPtr<CefBrowser> browser, CefRefPtr<CefFrame> frame,
                                       const CefString& scheme_name, CefRefPtr<CefRequest> request) override {
    if (!hook_) {
      return nullptr;
    }
    return new DeferredResourceHandler(this, browser, frame, scheme_name);
  }

  // On the PHP thread: the handler the PHP factory makes for this request (null if it makes none).
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
  zend_function* hook_ = nullptr;
  IMPLEMENT_REFCOUNTING(AsyncSchemeFactory);
  DISALLOW_COPY_AND_ASSIGN(AsyncSchemeFactory);
};

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

CefRefPtr<CefResourceHandler> cl_make_CefResourceHandler(zend_object* o) { return new AsyncResourceHandler(o); }

CefRefPtr<CefSchemeHandlerFactory> cl_make_CefSchemeHandlerFactory(zend_object* o) {
  return new AsyncSchemeFactory(o);
}

// ---- the generated proxies call these for their asynchronous methods ------------------------------------------------

namespace cl_async {

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

}  // namespace cl_async
