// cefloom runtime: what the generated code and the hand written classes share.
//
// Rules this file implements (see llm-wiki/pages/reference/cautions.md):
//  * PHP code only runs on the PHP main thread (run_on_php_thread marshals the rest);
//  * reference counting never touches PHP (ProxyBase releases PHP objects later, on the main thread);
//  * an exception of a PHP callback never travels into CEF: it stays pending and PHP raises it when the
//    PHP function that entered CEF (doMessageLoopWork, a method of a library class) returns.
#ifndef CEFLOOM_CL_RUNTIME_H_
#define CEFLOOM_CL_RUNTIME_H_

#include <atomic>
#include <condition_variable>
#include <cstdint>
#include <functional>
#include <map>
#include <memory>
#include <mutex>
#include <string>
#include <thread>
#include <unordered_map>
#include <unordered_set>
#include <vector>

#include "include/cef_api_hash.h"
#include "include/cef_app.h"
#include "include/cef_base.h"
#include "include/cef_callback.h"
#include "include/cef_task.h"

extern "C" {
#include "php.h"
#include "zend_exceptions.h"
#include "zend_interfaces.h"
#include "zend_smart_str.h"
}

// Make libcef.so available (src/core.cc). A build with CEFLOOM_LAZY_LIBCEF does not link it: it loads libcef.so from the
// directory of the module, or CEFLOOM_HOME, the first time it is needed. Returns false and sets |error| if it cannot.
bool cl_core_load_cef(std::string* error);

namespace cl {

// ---- process state -------------------------------------------------------------------------------

// True while the PHP request is running: only then may a callback touch PHP objects.
extern std::atomic<bool> g_php_alive;
// True from a successful CefInitialize() until CefShutdown().
extern std::atomic<bool> g_cef_running;
// The thread that runs PHP (the CEF UI thread with the external message pump).
bool on_php_thread();
void set_php_thread();

// ---- errors --------------------------------------------------------------------------------------

[[noreturn]] void fatal(const char* message);
void throw_error(const char* format, ...) ZEND_ATTRIBUTE_FORMAT(printf, 1, 2);

// ---- library objects (implemented by CEF, used by PHP) ----------------------------------------------

// CEF's classes inherit CefBaseRefCounted virtually, so a pointer to it cannot be turned back into a pointer to
// the class (static_cast is not allowed through a virtual base, and there is no RTTI). The wrapper therefore
// keeps one typed pointer for every class of the chain a PHP class derives from (CefView, CefPanel, CefWindow),
// root first: a method of the class at depth d uses up[d]. Classes without a parent use up[0] only.
constexpr int kMaxDepth = 4;

struct LibObject {
  CefBaseRefCounted* ptr;  // owns one reference
  void* up[kMaxDepth];
  zend_object std;
};

inline LibObject* lib_from(zend_object* o) {
  return reinterpret_cast<LibObject*>(reinterpret_cast<char*>(o) - XtOffsetOf(LibObject, std));
}
extern zend_object_handlers lib_handlers;
zend_object* lib_create(zend_class_entry* ce);

// The CEF object of a PHP object (null if it has been released), as the class at |depth| of its chain.
template <class T>
inline T* lib_ptr(zend_object* o, int depth) {
  return o ? static_cast<T*>(lib_from(o)->up[depth]) : nullptr;
}

// Called by every method of a library class: the object must still hold its CEF object.
template <class T>
inline T* self(zend_object* o, int depth) {
  T* p = lib_ptr<T>(o, depth);
  if (!p) {
    zend_throw_error(nullptr, "The %s object holds no native object (CEF has been shut down)",
                     ZSTR_VAL(o->ce->name));
  }
  return p;
}

// All live library objects, so that CEF can be shut down while PHP still holds some.
size_t release_all_lib_objects();

// ---- strings ---------------------------------------------------------------------------------------

inline CefString to_cef(const zend_string* s) {
  CefString r;
  if (s) {
    r.FromString(std::string(ZSTR_VAL(s), ZSTR_LEN(s)));
  }
  return r;
}
inline CefString to_cef(zval* zv) {
  zend_string* tmp = nullptr;
  zend_string* s = zval_get_tmp_string(zv, &tmp);
  CefString r = to_cef(s);
  zend_tmp_string_release(tmp);
  return r;
}
inline void ret_str(zval* rv, const CefString& s) {
  std::string u = s.ToString();
  ZVAL_STRINGL(rv, u.data(), u.size());
}
// A cef_string_t member of a C struct.
inline void ret_str(zval* rv, const cef_string_t& s) {
  ret_str(rv, CefString(&s));
}
inline void set_str(cef_string_t* field, zval* zv) {
  zend_string* tmp = nullptr;
  zend_string* s = zval_get_tmp_string(zv, &tmp);
  CefString(field).FromString(std::string(ZSTR_VAL(s), ZSTR_LEN(s)));
  zend_tmp_string_release(tmp);
}

// ---- times -----------------------------------------------------------------------------------------

// CefBaseTime is microseconds since 1601-01-01; PHP gets a DateTimeImmutable (null for 0).
void ret_time(zval* rv, int64_t microseconds_1601);
int64_t time_from(zval* zv);  // DateTimeInterface, int or float seconds since the Unix epoch; null is 0
// Store a time that a script gave (DateTimeInterface, int or float seconds since the Unix epoch) into a property
// typed ?DateTimeImmutable: as a DateTimeImmutable. Returns false, and stores nothing, for any other type (null is the
// caller's to handle).
bool time_normalize(zval* src, zval* dst);

// ---- value helpers ---------------------------------------------------------------------------------

inline void ret_bool(zval* rv, bool v) { ZVAL_BOOL(rv, v); }
inline void ret_long(zval* rv, int64_t v) { ZVAL_LONG(rv, static_cast<zend_long>(v)); }
inline void ret_double(zval* rv, double v) { ZVAL_DOUBLE(rv, v); }

// A string-keyed PHP array from a CEF string map and back (the last value of a repeated key wins).
void ret_strmap(zval* rv, const std::multimap<CefString, CefString>& m);
void ret_strmap(zval* rv, const std::map<CefString, CefString>& m);
void strmap_from(zval* zv, std::multimap<CefString, CefString>* m);
void strmap_from(zval* zv, std::map<CefString, CefString>* m);

// ---- buffers ---------------------------------------------------------------------------------------

// A window on memory that CEF owns, valid for the duration of one callback (class Cefloom\Buffer).
struct BufferObject {
  uint8_t* data;
  size_t size;
  bool writable;
  zend_object std;
};
inline BufferObject* buffer_from(zend_object* o) {
  return reinterpret_cast<BufferObject*>(reinterpret_cast<char*>(o) - XtOffsetOf(BufferObject, std));
}
extern zend_class_entry* ce_buffer;
// Make a PHP Buffer on |data|; call buffer_invalidate on the zval after the callback.
void buffer_new(zval* rv, void* data, size_t size, bool writable);
void buffer_invalidate(zval* zv);
// Invalidate a Buffer, or every Buffer in an array of them (the planes of an audio packet).
void invalidate_buffers(zval* zv);
// Copy the bytes of a PHP string, or of a Buffer, into |data| (at most |max| bytes); returns the count.
size_t buffer_fill(zval* zv, void* data, size_t max);

// ---- PHP objects held by C++ ------------------------------------------------------------------------

// Release a PHP object from any thread: directly on the PHP thread, else queued for it.
void release_later(zend_object* o);
// A completion callback that does nothing, for the places where CEF takes a NULL callback and then calls it anyway.
CefRefPtr<CefCompletionCallback> noop_completion();

// Run |fn| on a helper thread and wait for it: for the CEF functions that refuse the UI and IO threads (scope.py,
// OFF_UI_THREAD). |fn| must not call PHP.
template <class F>
auto off_ui_thread(F fn) {
  decltype(fn()) result{};
  std::thread worker([&]() { result = fn(); });
  worker.join();
  return result;
}

// Run the queued releases (the PHP thread calls it from its loop).
void drain_releases();

// The common part of the proxies: a C++ object CEF holds that stands for a PHP object.
class ProxyBase {
 public:
  explicit ProxyBase(zend_object* o);
  virtual ~ProxyBase();
  zend_object* object() const { return obj_; }

  // The PHP object of a proxy that CEF gave back (null if |object| is not one of ours).
  static zend_object* find(const CefBaseRefCounted* object);

  // |wrapper| (a C++ object that CEF holds in place of a proxy and that keeps the proxy alive) answers find() with the
  // PHP object of |proxy|; unalias() when the wrapper goes. This is how BrowserHost::getClient() gives back the client
  // that createBrowser() got, although CEF holds the client of the extension around it.
  static void alias(const CefBaseRefCounted* wrapper, const CefBaseRefCounted* proxy);
  static void unalias(const CefBaseRefCounted* wrapper);

 protected:
  void register_as(const CefBaseRefCounted* self);
  zend_object* obj_;
  const CefBaseRefCounted* registered_ = nullptr;
};

// The method of |ce| named |lower_name| if the user class overrides the one of |base|, else null.
zend_function* find_override(zend_class_entry* ce, zend_class_entry* base, const char* lower_name);

// ---- calling PHP -----------------------------------------------------------------------------------

// Milliseconds on the steady clock at which the PHP thread entered CefDoMessageLoopWork(), 0 while it is outside.
extern std::atomic<int64_t> g_pump_since_ms;
int64_t steady_ms();

// Run |fn| on the PHP thread and wait for it (directly when already there). Returns whether it ran. It does not
// run if CEF or PHP is going away, or if the call waited longer than CEFLOOM_SYNC_WAIT_MS (default 5000) while the
// PHP thread sat inside CefDoMessageLoopWork() without taking it: CEF's IO thread must not wait for ever for a PHP
// thread that waits for the IO thread (llm-wiki, verified-findings F14). The caller then does what CEF does
// without a handler.
bool run_on_php_thread(const std::function<void()>& fn);

// Call a method of a PHP object. An exception (including the one exit() throws) stays pending in PHP and
// the callbacks that follow do not run PHP until it has surfaced. Returns false if the call did not run
// or failed. Only the PHP thread may call it.
bool call_method(zend_object* obj, zend_function* fn, uint32_t argc, zval* argv, zval* retval);
bool call_function(zval* callable, uint32_t argc, zval* argv, zval* retval);

// The result number |index| of |count| results of a handler method (a single result is the return
// value itself, several are the elements of an array). Null if the handler returned too little.
zval* result_at(zval* rv, size_t count, size_t index);

// ---- module hooks ----------------------------------------------------------------------------------

void runtime_minit(int module_number);
void runtime_rinit();
void runtime_rshutdown();

}  // namespace cl

#endif  // CEFLOOM_CL_RUNTIME_H_
