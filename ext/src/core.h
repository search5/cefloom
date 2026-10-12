/**
 * @file
 * Hand written part of the extension: the lifetime and message loop of CEF (core.cc) and the JS bridge with
 * the client that carries it (bridge.cc).
 */
#ifndef CEFLOOM_CORE_H_
#define CEFLOOM_CORE_H_

#include <string>
#include "cl_gen.h"
#include "forwarders.h"
#include "include/wrapper/cef_message_router.h"

/** Register the hand written methods of the class Cef (MINIT). */
void cl_core_register();
/** Run at request start (RINIT); currently does nothing. */
void cl_core_rinit();
/**
 * Shut CEF down at request end if the script did not, then release the bridge (RSHUTDOWN).
 * @note Thread: PHP.
 */
void cl_core_rshutdown();

/**
 * Return the number of live browsers the native client has seen created.
 * @note Thread: PHP.
 */
size_t cl_core_browser_count();
/**
 * Add (@p created true, after OnAfterCreated) or remove (false, before the browser goes away) @p browser in the list of live browsers.
 * @note Thread: PHP.
 */
void cl_core_note_browser(const CefRefPtr<CefBrowser>& browser, bool created);
/**
 * Return a snapshot of the live browsers.
 * @note Thread: PHP.
 */
std::vector<CefRefPtr<CefBrowser>> cl_core_browsers();

/**
 * Return the client every browser gets.
 * It carries the router of the JS bridge and passes everything else on to the client the PHP script gave, @p user (may be null).
 */
CefRefPtr<CefClient> cl_bridge_wrap_client(CefRefPtr<CefClient> user);
/**
 * Create the router of the JS bridge and add its handler; call after CefInitialize().
 * @note Thread: PHP.
 */
void cl_bridge_init();
/**
 * Remove the bridge handler and drop the exposed functions; call before CefShutdown().
 * @note Thread: PHP.
 */
void cl_bridge_shutdown();
/**
 * Expose the PHP @p callable to JavaScript as @p name (window.cefloom.call(name, ...args)), replacing an earlier one.
 * A reference to @p callable is kept.
 * @note Thread: PHP.
 */
void cl_bridge_expose(zend_string* name, zval* callable);

#endif  // CEFLOOM_CORE_H_
