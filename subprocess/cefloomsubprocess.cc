/**
 * @file
 * The executable CEF starts for the renderer, GPU and utility processes of a PHP program.
 *
 * It runs no PHP. In the renderer it defines `window.cefloom.call(name, ...args)` in every frame; the call
 * travels through CEF's message router to the PHP process, which answers it (ext/src/bridge.cc).
 */
#include <cstdlib>
#include <string>

#include "include/cef_app.h"
#include "include/cef_command_line.h"
#include "include/cef_scheme.h"
#include "include/wrapper/cef_message_router.h"

namespace {

/** JavaScript run in every frame: define window.cefloom.call(), which returns a Promise for the answer of the PHP process. */
const char kBridgeScript[] = R"JS(
(function () {
  if (window.cefloom) return;
  window.cefloom = {
    call: function (name) {
      var args = Array.prototype.slice.call(arguments, 1);
      return new Promise(function (resolve, reject) {
        window.cefQuery({
          request: JSON.stringify({name: name, args: args}),
          onSuccess: function (response) { resolve(response === '' ? null : JSON.parse(response)); },
          onFailure: function (code, message) {
            var error = new Error(message);
            error.code = code;
            reject(error);
          }
        });
      });
    }
  };
})();
)JS";

/**
 * The app of the helper processes: registers the custom schemes and the JS bridge in the renderer.
 * @note Thread: the render process handler callbacks run on the render process main thread (TID_RENDERER);
 *       OnRegisterCustomSchemes() runs in every process.
 */
class SubprocessApp : public CefApp, public CefRenderProcessHandler {
 public:
  /** Return this object as the render process handler. */
  CefRefPtr<CefRenderProcessHandler> GetRenderProcessHandler() override { return this; }

  /**
   * Register the custom schemes the PHP script declared.
   * Read from the "cefloom-schemes" switch, "name:options,name:options" (see core.cc); nothing happens without it.
   */
  void OnRegisterCustomSchemes(CefRawPtr<CefSchemeRegistrar> registrar) override {
    CefRefPtr<CefCommandLine> command_line = CefCommandLine::GetGlobalCommandLine();
    if (!command_line || !command_line->HasSwitch("cefloom-schemes")) {
      return;
    }
    std::string list = command_line->GetSwitchValue("cefloom-schemes").ToString();
    size_t start = 0;
    while (start < list.size()) {
      size_t end = list.find(',', start);
      if (end == std::string::npos) end = list.size();
      std::string entry = list.substr(start, end - start);
      size_t colon = entry.find(':');
      if (colon != std::string::npos) {
        registrar->AddCustomScheme(entry.substr(0, colon), atoi(entry.c_str() + colon + 1));
      }
      start = end + 1;
    }
  }

  /** Create the message router of the renderer side. */
  void OnWebKitInitialized() override {
    CefMessageRouterConfig config;
    router_ = CefMessageRouterRendererSide::Create(config);
  }

  /** Attach the router to the new context and define window.cefloom in the frame. */
  void OnContextCreated(CefRefPtr<CefBrowser> browser, CefRefPtr<CefFrame> frame, CefRefPtr<CefV8Context> context) override {
    router_->OnContextCreated(browser, frame, context);
    frame->ExecuteJavaScript(kBridgeScript, frame->GetURL(), 0);
  }

  /** Detach the router from the released context. */
  void OnContextReleased(CefRefPtr<CefBrowser> browser, CefRefPtr<CefFrame> frame, CefRefPtr<CefV8Context> context) override {
    router_->OnContextReleased(browser, frame, context);
  }

  /** Pass a message to the router; return true if it was handled. */
  bool OnProcessMessageReceived(CefRefPtr<CefBrowser> browser, CefRefPtr<CefFrame> frame, CefProcessId source_process,
                                CefRefPtr<CefProcessMessage> message) override {
    return router_->OnProcessMessageReceived(browser, frame, source_process, message);
  }

 private:
  /** The renderer side of the message router; created by OnWebKitInitialized(). */
  CefRefPtr<CefMessageRouterRendererSide> router_;
  /** Reference counting of the app. */
  IMPLEMENT_REFCOUNTING(SubprocessApp);
};

}  // namespace

/** Entry point: run the helper process CEF started and return its exit code. */
int main(int argc, char** argv) {
  CefMainArgs main_args(argc, argv);
  CefRefPtr<CefApp> app(new SubprocessApp);
  return CefExecuteProcess(main_args, app, nullptr);
}
