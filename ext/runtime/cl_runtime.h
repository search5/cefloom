/**
 * @file
 * Shared runtime of the extension: what the generated code and the hand written classes use.
 *
 * Rules this file implements (see llm-wiki/pages/reference/cautions.md):
 *  - PHP code only runs on the PHP main thread (run_on_php_thread() marshals the rest);
 *  - reference counting never touches PHP (ProxyBase releases PHP objects later, on the main thread);
 *  - an exception of a PHP callback never travels into CEF: it stays pending and PHP raises it when the
 *    PHP function that entered CEF (doMessageLoopWork, a method of a library class) returns.
 */
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
#include "include/views/cef_panel.h"

extern "C" {
#include "php.h"
#include "zend_exceptions.h"
#include "zend_interfaces.h"
#include "zend_smart_str.h"
}

/**
 * Make libcef.so available (src/core.cc).
 *
 * A build with CEFLOOM_LAZY_LIBCEF does not link libcef.so: it loads it from the directory of the module, or from
 * CEFLOOM_HOME, the first time it is needed.
 * @param[out] error Receives the reason when the load fails.
 * @return false if libcef.so cannot be loaded.
 */
bool cl_core_load_cef(std::string* error);
/** Return the client that carries the JS bridge router and forwards the rest to @p user (src/bridge.cc). */
CefRefPtr<CefClient> cl_bridge_wrap_client(CefRefPtr<CefClient> user);

namespace cl {

// ---- process state -------------------------------------------------------------------------------

/** True while the PHP request is running: only then may a callback touch PHP objects. */
extern std::atomic<bool> g_php_alive;
/** True from a successful CefInitialize() until CefShutdown(). */
extern std::atomic<bool> g_cef_running;
/** Return whether the calling thread is the PHP thread, which is also the CEF UI thread. */
bool on_php_thread();
/** Record the calling thread as the PHP thread; called once per request, from RINIT. */
void set_php_thread();

// ---- errors --------------------------------------------------------------------------------------

/**
 * Raise a fatal PHP error ("cefloom: <message>") and bail out of the script.
 * @note Does not return.
 */
[[noreturn]] void fatal(const char* message);
/**
 * Throw a PHP Error with a printf-style message, truncated to 511 bytes.
 * @note Thread: PHP.
 */
void throw_error(const char* format, ...) ZEND_ATTRIBUTE_FORMAT(printf, 1, 2);

// ---- library objects (implemented by CEF, used by PHP) ----------------------------------------------

/**
 * Number of levels of the longest class chain a PHP library class derives from.
 *
 * CEF's classes inherit CefBaseRefCounted virtually, so a pointer to it cannot be turned back into a pointer to
 * the class (static_cast is not allowed through a virtual base, and there is no RTTI). A LibObject therefore
 * keeps one typed pointer for every class of the chain a PHP class derives from (for CefMenuButton: CefView, CefButton,
 * CefLabelButton, CefMenuButton),
 * root first: a method of the class at depth d uses LibObject::up[d]. Classes without a parent use up[0] only.
 */
constexpr int kMaxDepth = 4;

/** The PHP object that wraps one CEF object. */
struct LibObject {
  /** The CEF object; owns one reference. Null once released. */
  CefBaseRefCounted* ptr;
  /** Typed pointers to @c ptr as each class of the chain, indexed by depth (see kMaxDepth). */
  void* up[kMaxDepth];
  /** The embedded PHP object; PHP requires it to be the last member. */
  zend_object std;
};

/** Return the LibObject that embeds @p o. */
inline LibObject* lib_from(zend_object* o) {
  return reinterpret_cast<LibObject*>(reinterpret_cast<char*>(o) - XtOffsetOf(LibObject, std));
}
/** Object handlers of every library class. */
extern zend_object_handlers lib_handlers;
/**
 * Create_object handler of the library classes: return a PHP object that holds no CEF object yet.
 * The object is tracked for release_all_lib_objects().
 */
zend_object* lib_create(zend_class_entry* ce);

/**
 * Return the CEF object of @p o as the class at @p depth of its chain, or null if @p o is null or has been released.
 */
template <class T>
inline T* lib_ptr(zend_object* o, int depth) {
  return o ? static_cast<T*>(lib_from(o)->up[depth]) : nullptr;
}

/**
 * Return the CEF object of @p o at @p depth for a method of a library class.
 * @throws Error (pending, null returned) if the object holds no native object.
 */
template <class T>
inline T* self(zend_object* o, int depth) {
  T* p = lib_ptr<T>(o, depth);
  if (!p) {
    zend_throw_error(nullptr, "The %s object holds no native object (CEF has been shut down)",
                     ZSTR_VAL(o->ce->name));
  }
  return p;
}

/**
 * Release the CEF object of every live library object so that CEF can be shut down while PHP still holds some.
 * @return the number of objects released.
 * @note Thread: PHP.
 */
size_t release_all_lib_objects();

// ---- strings ---------------------------------------------------------------------------------------

/** Convert a PHP string to a CefString; null gives an empty string. */
inline CefString to_cef(const zend_string* s) {
  CefString r;
  if (s) {
    r.FromString(std::string(ZSTR_VAL(s), ZSTR_LEN(s)));
  }
  return r;
}
/** Convert a zval to a CefString after PHP's string conversion. */
inline CefString to_cef(zval* zv) {
  zend_string* tmp = nullptr;
  zend_string* s = zval_get_tmp_string(zv, &tmp);
  CefString r = to_cef(s);
  zend_tmp_string_release(tmp);
  return r;
}
/** Set @p rv to a PHP string with the text of @p s. */
inline void ret_str(zval* rv, const CefString& s) {
  std::string u = s.ToString();
  ZVAL_STRINGL(rv, u.data(), u.size());
}
/** Set @p rv to a PHP string with the text of a cef_string_t member of a C struct. */
inline void ret_str(zval* rv, const cef_string_t& s) {
  ret_str(rv, CefString(&s));
}
/** Replace the content of the cef_string_t @p field with the string value of @p zv. */
inline void set_str(cef_string_t* field, zval* zv) {
  zend_string* tmp = nullptr;
  zend_string* s = zval_get_tmp_string(zv, &tmp);
  CefString(field).FromString(std::string(ZSTR_VAL(s), ZSTR_LEN(s)));
  zend_tmp_string_release(tmp);
}

// ---- times -----------------------------------------------------------------------------------------

/**
 * Set @p rv to the time given as microseconds since 1601-01-01 (CefBaseTime): a DateTimeImmutable, or null for 0.
 * Falls back to an int of Unix seconds if DateTimeImmutable is unavailable.
 */
void ret_time(zval* rv, int64_t microseconds_1601);
/**
 * Return the time in @p zv as microseconds since 1601-01-01.
 * @p zv is a DateTimeInterface, or an int or float of seconds since the Unix epoch; null gives 0.
 */
int64_t time_from(zval* zv);
/**
 * Store a time that a script gave into the property @p dst, typed ?DateTimeImmutable, as a DateTimeImmutable.
 * @p src is a DateTimeInterface, or an int or float of seconds since the Unix epoch.
 * @return false, storing nothing, for any other type (null is the caller's to handle).
 */
bool time_normalize(zval* src, zval* dst);

// ---- value helpers ---------------------------------------------------------------------------------

/** Set @p rv to a PHP bool. */
inline void ret_bool(zval* rv, bool v) { ZVAL_BOOL(rv, v); }
/** Set @p rv to a PHP int. */
inline void ret_long(zval* rv, int64_t v) { ZVAL_LONG(rv, static_cast<zend_long>(v)); }
/** Set @p rv to a PHP float. */
inline void ret_double(zval* rv, double v) { ZVAL_DOUBLE(rv, v); }

/**
 * Set @p rv to a string-keyed PHP array from a CEF string map; the last value of a repeated key wins.
 */
void ret_strmap(zval* rv, const std::multimap<CefString, CefString>& m);
/** Overload for std::map. */
void ret_strmap(zval* rv, const std::map<CefString, CefString>& m);
/**
 * Fill @p m from a PHP array (keys and values converted to strings); any other type leaves @p m unchanged.
 */
void strmap_from(zval* zv, std::multimap<CefString, CefString>* m);
/** Overload for std::map. */
void strmap_from(zval* zv, std::map<CefString, CefString>* m);

// ---- buffers ---------------------------------------------------------------------------------------

/** A window on memory that CEF owns, valid for the duration of one callback (class Cefloom\Buffer). */
struct BufferObject {
  /** The memory; null once the buffer is invalidated. */
  uint8_t* data;
  /** Size of the memory in bytes. */
  size_t size;
  /** Whether write() may modify the memory. */
  bool writable;
  /** The embedded PHP object; PHP requires it to be the last member. */
  zend_object std;
};
/** Return the BufferObject that embeds @p o. */
inline BufferObject* buffer_from(zend_object* o) {
  return reinterpret_cast<BufferObject*>(reinterpret_cast<char*>(o) - XtOffsetOf(BufferObject, std));
}
/** Class entry of Cefloom\Buffer; set by runtime_minit(). */
extern zend_class_entry* ce_buffer;
/**
 * Make a PHP Buffer over @p data (not copied, not owned).
 * The caller must call buffer_invalidate() on the zval after the callback.
 */
void buffer_new(zval* rv, void* data, size_t size, bool writable);
/** Make the Buffer in @p zv unusable (size 0, no memory); other values are ignored. */
void buffer_invalidate(zval* zv);
/** Invalidate a Buffer, or every Buffer in an array of them (the planes of an audio packet). */
void invalidate_buffers(zval* zv);
/**
 * Copy the bytes of a PHP string, or of a Buffer, into @p data, at most @p max bytes.
 * @return the number of bytes copied (0 if the source or @p data is null).
 */
size_t buffer_fill(zval* zv, void* data, size_t max);

// ---- PHP objects held by C++ ------------------------------------------------------------------------

/**
 * Release a PHP object from any thread: directly on the PHP thread, else queued for it.
 * The object is dropped silently once PHP is gone.
 * @note Thread: any.
 */
void release_later(zend_object* o);
/**
 * Return a completion callback that does nothing. The generated code passes it where the script gave null for a
 * CefCompletionCallback argument.
 * @note Thread: any.
 */
CefRefPtr<CefCompletionCallback> noop_completion();

/**
 * Refuse to take a BrowserView with a live browser out of its window.
 * Such a view cannot close its browser later, and CEF ends the process (K42). Throws a PHP Error and returns true when
 * @p view has a live browser; looks at the given view only.
 * @return true if the error was thrown.
 */
bool refuse_live_browser_view(CefRefPtr<CefView> view);
/**
 * Apply refuse_live_browser_view() to the direct children of @p panel.
 * @return true if the error was thrown.
 */
bool refuse_live_browser_views_of(CefRefPtr<CefPanel> panel);

/**
 * Run @p fn on a helper thread and wait for it, and return its result.
 * For the CEF functions that refuse the UI and IO threads (scope.py, OFF_UI_THREAD). @p fn must not call PHP.
 */
template <class F>
auto off_ui_thread(F fn) {
  decltype(fn()) result{};
  std::thread worker([&]() { result = fn(); });
  worker.join();
  return result;
}

/**
 * Run the queued releases of release_later().
 * @note Thread: PHP (called from its loop).
 */
void drain_releases();

/**
 * The common part of the proxies: a C++ object that CEF holds and that stands for a PHP object.
 * Holds one reference to the PHP object; releases it later on the PHP thread.
 */
class ProxyBase {
 public:
  /**
   * Hold a reference to @p o.
   * @note Thread: PHP.
   */
  explicit ProxyBase(zend_object* o);
  /**
   * Unregister the proxy and release the PHP object through release_later().
   * @note Thread: any.
   */
  virtual ~ProxyBase();
  /** Return the PHP object this proxy stands for. */
  zend_object* object() const { return obj_; }

  /**
   * Return the PHP object of a proxy that CEF gave back, or null if @p object is not one of ours.
   */
  static zend_object* find(const CefBaseRefCounted* object);

  /**
   * Make @p wrapper answer find() with the PHP object of @p proxy.
   * @p wrapper is a C++ object that CEF holds in place of a proxy and that keeps the proxy alive; call unalias() when it goes.
   * This is how BrowserHost::getClient() gives back the client that createBrowser() got, although CEF holds the client of
   * the extension around it.
   */
  static void alias(const CefBaseRefCounted* wrapper, const CefBaseRefCounted* proxy);
  /** Remove the alias made by alias() for @p wrapper. */
  static void unalias(const CefBaseRefCounted* wrapper);

 protected:
  /**
   * Register this proxy as the answer of find() for @p self.
   */
  void register_as(const CefBaseRefCounted* self);
  /** The PHP object this proxy stands for; one reference is held. */
  zend_object* obj_;
  /** The object registered with register_as(), or null. */
  const CefBaseRefCounted* registered_ = nullptr;
};

/**
 * Return the method of @p ce named @p lower_name (lower case) if the user class overrides the one of @p base, else null.
 */
zend_function* find_override(zend_class_entry* ce, zend_class_entry* base, const char* lower_name);

// ---- calling PHP -----------------------------------------------------------------------------------

/**
 * Milliseconds on the steady clock at which the PHP thread entered CefDoMessageLoopWork(); 0 while it is outside.
 */
extern std::atomic<int64_t> g_pump_since_ms;
/** Return the current time of the steady clock in milliseconds. */
int64_t steady_ms();

/**
 * Run @p fn on the PHP thread and wait for it (directly when already there).
 * It does not run if CEF or PHP is going away, or if the call waited longer than CEFLOOM_SYNC_WAIT_MS (default 5000) while the
 * PHP thread sat inside CefDoMessageLoopWork() without taking it: CEF's IO thread must not wait for ever for a PHP
 * thread that waits for the IO thread (llm-wiki, verified-findings F14). The caller then does what CEF does
 * without a handler.
 * @return whether @p fn ran.
 * @note Thread: any.
 */
bool run_on_php_thread(const std::function<void()>& fn);

/**
 * Call a method of a PHP object.
 * An exception (including the one exit() throws) stays pending in PHP and the callbacks that follow do not run PHP
 * until it has surfaced.
 * @return false if the call did not run or failed.
 * @note Thread: PHP only.
 */
bool call_method(zend_object* obj, zend_function* fn, uint32_t argc, zval* argv, zval* retval);
/**
 * Call a PHP callable with the contract of call_method().
 */
bool call_function(zval* callable, uint32_t argc, zval* argv, zval* retval);

/**
 * Return result number @p index of @p count results of a handler method.
 * A single result is the return value itself, several are the elements of an array.
 * @return null if the handler returned too little.
 */
zval* result_at(zval* rv, size_t count, size_t index);

// ---- module hooks ----------------------------------------------------------------------------------

/**
 * Module startup (MINIT): set up the object handlers and register Cefloom\Buffer.
 */
void runtime_minit(int module_number);
/**
 * Request startup (RINIT): mark the current thread as the PHP thread and PHP as alive.
 */
void runtime_rinit();
/**
 * Request shutdown (RSHUTDOWN): run the pending releases and mark PHP as gone.
 */
void runtime_rshutdown();

}  // namespace cl

#endif  // CEFLOOM_CL_RUNTIME_H_
