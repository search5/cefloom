// The executable CEF starts for the renderer, GPU and utility processes of a PHP program.
//
// It runs no PHP. In the renderer it defines `window.cefloom.call(name, ...args)` in every frame; the call
// travels through CEF's message router to the PHP process, which answers it (ext/src/bridge.cc).
#include <cstdlib>
#include <string>

#include "include/cef_app.h"
#include "include/cef_command_line.h"
#include "include/cef_scheme.h"
#include "include/wrapper/cef_message_router.h"

namespace {

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

class SubprocessApp : public CefApp, public CefRenderProcessHandler {
 public:
  CefRefPtr<CefRenderProcessHandler> GetRenderProcessHandler() override { return this; }

  // The custom schemes of the PHP script: "name:options,name:options" on the command line (see core.cc).
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

  void OnWebKitInitialized() override {
    CefMessageRouterConfig config;
    router_ = CefMessageRouterRendererSide::Create(config);
  }

  void OnContextCreated(CefRefPtr<CefBrowser> browser, CefRefPtr<CefFrame> frame, CefRefPtr<CefV8Context> context) override {
    router_->OnContextCreated(browser, frame, context);
    frame->ExecuteJavaScript(kBridgeScript, frame->GetURL(), 0);
  }

  void OnContextReleased(CefRefPtr<CefBrowser> browser, CefRefPtr<CefFrame> frame, CefRefPtr<CefV8Context> context) override {
    router_->OnContextReleased(browser, frame, context);
  }

  bool OnProcessMessageReceived(CefRefPtr<CefBrowser> browser, CefRefPtr<CefFrame> frame, CefProcessId source_process,
                                CefRefPtr<CefProcessMessage> message) override {
    return router_->OnProcessMessageReceived(browser, frame, source_process, message);
  }

 private:
  CefRefPtr<CefMessageRouterRendererSide> router_;
  IMPLEMENT_REFCOUNTING(SubprocessApp);
};

}  // namespace

int main(int argc, char** argv) {
  CefMainArgs main_args(argc, argv);
  CefRefPtr<CefApp> app(new SubprocessApp);
  return CefExecuteProcess(main_args, app, nullptr);
}
