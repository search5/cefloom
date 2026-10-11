// Hand written part of the extension: the lifetime and message loop of CEF (core.cc) and the JS bridge with
// the client that carries it (bridge.cc).
#ifndef CEFLOOM_CORE_H_
#define CEFLOOM_CORE_H_

#include "cl_gen.h"
#include "forwarders.h"
#include "include/wrapper/cef_message_router.h"

// Module hooks (cefloom.cc).
void cl_core_register();   // MINIT: the hand written methods of the class Cef
void cl_core_rinit();
void cl_core_rshutdown();  // RSHUTDOWN: CEF is shut down if the script forgot

// The browsers the native client has seen created (UI thread only).
size_t cl_core_browser_count();
void cl_core_note_browser(const CefRefPtr<CefBrowser>& browser, bool created);
std::vector<CefRefPtr<CefBrowser>> cl_core_browsers();

// The client every browser gets: it carries the router of the JS bridge and passes everything else on to the
// client the PHP script gave (which may be null).
CefRefPtr<CefClient> cl_bridge_wrap_client(CefRefPtr<CefClient> user);
void cl_bridge_init();      // after CefInitialize()
void cl_bridge_shutdown();  // before CefShutdown()
void cl_bridge_expose(zend_string* name, zval* callable);

#endif  // CEFLOOM_CORE_H_
