#include "cl_runtime.h"

#include <chrono>
#include <cstdio>
#include <cstdlib>

#include <chrono>
#include <cstdarg>
#include <cstring>

#include "include/wrapper/cef_helpers.h"

namespace cl {

std::atomic<bool> g_php_alive{false};
std::atomic<bool> g_cef_running{false};

namespace {
std::thread::id g_php_thread;
std::atomic<bool> g_php_thread_set{false};

// Live library objects (the PHP thread only).
std::unordered_set<LibObject*>& live_objects() {
  static std::unordered_set<LibObject*> set;
  return set;
}

// Releases requested from other threads.
std::mutex g_release_mutex;
std::vector<zend_object*> g_release_queue;

// Proxies that CEF can give back.
std::mutex g_proxy_mutex;
std::unordered_map<const CefBaseRefCounted*, zend_object*>& proxy_registry() {
  static std::unordered_map<const CefBaseRefCounted*, zend_object*> registry;
  return registry;
}
}  // namespace

bool on_php_thread() {
  return g_php_thread_set.load() && std::this_thread::get_id() == g_php_thread;
}

void set_php_thread() {
  g_php_thread = std::this_thread::get_id();
  g_php_thread_set = true;
}

[[noreturn]] void fatal(const char* message) {
  zend_error_noreturn(E_ERROR, "cefloom: %s", message);
}

void throw_error(const char* format, ...) {
  char buffer[512];
  va_list args;
  va_start(args, format);
  vsnprintf(buffer, sizeof(buffer), format, args);
  va_end(args);
  zend_throw_error(nullptr, "%s", buffer);
}

// ---- library objects -------------------------------------------------------------------------------

zend_object_handlers lib_handlers;

static void lib_free(zend_object* o) {
  LibObject* obj = lib_from(o);
  live_objects().erase(obj);
  zend_object_std_dtor(o);
  if (obj->ptr) {
    obj->ptr->Release();
    obj->ptr = nullptr;
  }
}

zend_object* lib_create(zend_class_entry* ce) {
  LibObject* obj = static_cast<LibObject*>(zend_object_alloc(sizeof(LibObject), ce));
  zend_object_std_init(&obj->std, ce);
  object_properties_init(&obj->std, ce);
  obj->std.handlers = &lib_handlers;
  obj->ptr = nullptr;
  for (void*& up : obj->up) {
    up = nullptr;
  }
  live_objects().insert(obj);
  return &obj->std;
}

size_t release_all_lib_objects() {
  size_t count = 0;
  for (LibObject* obj : live_objects()) {
    if (obj->ptr) {
      obj->ptr->Release();
      obj->ptr = nullptr;
      for (void*& up : obj->up) {
        up = nullptr;
      }
      ++count;
    }
  }
  return count;
}

// ---- times -----------------------------------------------------------------------------------------

static const int64_t kEpochDelta = 11644473600000000LL;  // microseconds between 1601 and 1970

void ret_time(zval* rv, int64_t microseconds_1601) {
  if (microseconds_1601 == 0) {
    ZVAL_NULL(rv);
    return;
  }
  int64_t unix_us = microseconds_1601 - kEpochDelta;
  int64_t sec = unix_us / 1000000;
  int64_t us = unix_us % 1000000;
  if (us < 0) {
    us += 1000000;
    --sec;
  }
  char text[64];
  snprintf(text, sizeof(text), "@%lld.%06lld", static_cast<long long>(sec), static_cast<long long>(us));
  zend_string* name = zend_string_init("DateTimeImmutable", sizeof("DateTimeImmutable") - 1, false);
  zend_class_entry* ce = zend_lookup_class(name);
  zend_string_release(name);
  if (!ce) {
    ZVAL_LONG(rv, sec);
    return;
  }
  object_init_ex(rv, ce);
  zval arg;
  ZVAL_STRING(&arg, text);
  zend_call_known_instance_method_with_1_params(ce->constructor, Z_OBJ_P(rv), nullptr, &arg);
  zval_ptr_dtor(&arg);
}

int64_t time_from(zval* zv) {
  ZVAL_DEREF(zv);
  if (Z_TYPE_P(zv) == IS_NULL) {
    return 0;
  }
  if (Z_TYPE_P(zv) == IS_OBJECT) {
    zval ts, us, fmt;
    ZVAL_UNDEF(&ts);
    ZVAL_UNDEF(&us);
    ZVAL_STRING(&fmt, "u");
    zend_call_method_with_0_params(Z_OBJ_P(zv), Z_OBJCE_P(zv), nullptr, "gettimestamp", &ts);
    zend_call_method_with_1_params(Z_OBJ_P(zv), Z_OBJCE_P(zv), nullptr, "format", &us, &fmt);
    int64_t sec = Z_TYPE(ts) == IS_LONG ? Z_LVAL(ts) : 0;
    int64_t micro = Z_TYPE(us) == IS_STRING ? atoll(Z_STRVAL(us)) : 0;
    zval_ptr_dtor(&ts);
    zval_ptr_dtor(&us);
    zval_ptr_dtor(&fmt);
    return sec * 1000000 + micro + kEpochDelta;
  }
  double seconds = zval_get_double(zv);
  return static_cast<int64_t>(seconds * 1000000.0) + kEpochDelta;
}

// ---- string maps -----------------------------------------------------------------------------------

template <class Map>
static void ret_map(zval* rv, const Map& m) {
  array_init(rv);
  for (const auto& entry : m) {
    std::string key = entry.first.ToString();
    std::string value = entry.second.ToString();
    zval v;
    ZVAL_STRINGL(&v, value.data(), value.size());
    zend_symtable_str_update(Z_ARRVAL_P(rv), key.data(), key.size(), &v);
  }
}

template <class Map>
static void map_from(zval* zv, Map* m) {
  ZVAL_DEREF(zv);
  if (Z_TYPE_P(zv) != IS_ARRAY) {
    return;
  }
  zend_ulong index;
  zend_string* key;
  zval* value;
  ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(zv), index, key, value) {
    CefString k;
    if (key) {
      k = to_cef(key);
    } else {
      k.FromString(std::to_string(index));
    }
    m->insert({k, to_cef(value)});
  }
  ZEND_HASH_FOREACH_END();
}

void ret_strmap(zval* rv, const std::multimap<CefString, CefString>& m) { ret_map(rv, m); }
void ret_strmap(zval* rv, const std::map<CefString, CefString>& m) { ret_map(rv, m); }
void strmap_from(zval* zv, std::multimap<CefString, CefString>* m) { map_from(zv, m); }
void strmap_from(zval* zv, std::map<CefString, CefString>* m) { map_from(zv, m); }

// ---- Buffer ----------------------------------------------------------------------------------------

zend_class_entry* ce_buffer = nullptr;
static zend_object_handlers buffer_handlers;

static zend_object* buffer_create(zend_class_entry* ce) {
  BufferObject* obj = static_cast<BufferObject*>(zend_object_alloc(sizeof(BufferObject), ce));
  zend_object_std_init(&obj->std, ce);
  object_properties_init(&obj->std, ce);
  obj->std.handlers = &buffer_handlers;
  obj->data = nullptr;
  obj->size = 0;
  obj->writable = false;
  return &obj->std;
}

void buffer_new(zval* rv, void* data, size_t size, bool writable) {
  object_init_ex(rv, ce_buffer);
  BufferObject* obj = buffer_from(Z_OBJ_P(rv));
  obj->data = static_cast<uint8_t*>(data);
  obj->size = size;
  obj->writable = writable;
}

void buffer_invalidate(zval* zv) {
  if (Z_TYPE_P(zv) == IS_OBJECT && Z_OBJCE_P(zv) == ce_buffer) {
    BufferObject* obj = buffer_from(Z_OBJ_P(zv));
    obj->data = nullptr;
    obj->size = 0;
  }
}

void invalidate_buffers(zval* zv) {
  if (Z_TYPE_P(zv) == IS_ARRAY) {
    zval* item;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(zv), item) { buffer_invalidate(item); }
    ZEND_HASH_FOREACH_END();
  } else {
    buffer_invalidate(zv);
  }
}

size_t buffer_fill(zval* zv, void* data, size_t max) {
  ZVAL_DEREF(zv);
  const void* source = nullptr;
  size_t length = 0;
  if (Z_TYPE_P(zv) == IS_STRING) {
    source = Z_STRVAL_P(zv);
    length = Z_STRLEN_P(zv);
  } else if (Z_TYPE_P(zv) == IS_OBJECT && Z_OBJCE_P(zv) == ce_buffer) {
    BufferObject* obj = buffer_from(Z_OBJ_P(zv));
    source = obj->data;
    length = obj->size;
  }
  if (!source || !data) {
    return 0;
  }
  if (length > max) {
    length = max;
  }
  memcpy(data, source, length);
  return length;
}

static BufferObject* buffer_valid(zend_object* o) {
  BufferObject* obj = buffer_from(o);
  if (!obj->data) {
    zend_throw_error(nullptr, "The Cefloom\\Buffer is only valid during the callback that received it");
    return nullptr;
  }
  return obj;
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_buffer_getSize, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_buffer_isValid, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_buffer_isWritable, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_buffer_read, 0, 0, IS_STRING, 0)
ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, offset, IS_LONG, 0, "0")
ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, length, IS_LONG, 1, "null")
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_buffer_write, 0, 1, IS_LONG, 0)
ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, offset, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_INFO_EX(arginfo_buffer_construct, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_METHOD(CefloomBuffer, __construct) {}

ZEND_METHOD(CefloomBuffer, getSize) {
  ZEND_PARSE_PARAMETERS_NONE();
  BufferObject* obj = buffer_valid(Z_OBJ_P(ZEND_THIS));
  if (obj) {
    RETURN_LONG(static_cast<zend_long>(obj->size));
  }
}

ZEND_METHOD(CefloomBuffer, isValid) {
  ZEND_PARSE_PARAMETERS_NONE();
  RETURN_BOOL(buffer_from(Z_OBJ_P(ZEND_THIS))->data != nullptr);
}

ZEND_METHOD(CefloomBuffer, isWritable) {
  ZEND_PARSE_PARAMETERS_NONE();
  RETURN_BOOL(buffer_from(Z_OBJ_P(ZEND_THIS))->writable);
}

ZEND_METHOD(CefloomBuffer, read) {
  zend_long offset = 0, length = 0;
  bool length_is_null = true;
  ZEND_PARSE_PARAMETERS_START(0, 2)
  Z_PARAM_OPTIONAL
  Z_PARAM_LONG(offset)
  Z_PARAM_LONG_OR_NULL(length, length_is_null)
  ZEND_PARSE_PARAMETERS_END();
  BufferObject* obj = buffer_valid(Z_OBJ_P(ZEND_THIS));
  if (!obj) {
    return;
  }
  if (offset < 0 || static_cast<size_t>(offset) > obj->size) {
    zend_argument_value_error(1, "must be between 0 and the size of the buffer");
    return;
  }
  size_t available = obj->size - static_cast<size_t>(offset);
  size_t count = length_is_null ? available : (length < 0 ? 0 : static_cast<size_t>(length));
  if (count > available) {
    count = available;
  }
  RETURN_STRINGL(reinterpret_cast<const char*>(obj->data) + offset, count);
}

ZEND_METHOD(CefloomBuffer, write) {
  zend_string* data;
  zend_long offset = 0;
  ZEND_PARSE_PARAMETERS_START(1, 2)
  Z_PARAM_STR(data)
  Z_PARAM_OPTIONAL
  Z_PARAM_LONG(offset)
  ZEND_PARSE_PARAMETERS_END();
  BufferObject* obj = buffer_valid(Z_OBJ_P(ZEND_THIS));
  if (!obj) {
    return;
  }
  if (!obj->writable) {
    zend_throw_error(nullptr, "This Cefloom\\Buffer is read-only");
    return;
  }
  if (offset < 0 || static_cast<size_t>(offset) > obj->size) {
    zend_argument_value_error(2, "must be between 0 and the size of the buffer");
    return;
  }
  size_t count = ZSTR_LEN(data);
  size_t available = obj->size - static_cast<size_t>(offset);
  if (count > available) {
    count = available;
  }
  memcpy(obj->data + offset, ZSTR_VAL(data), count);
  RETURN_LONG(static_cast<zend_long>(count));
}

static const zend_function_entry buffer_methods[] = {
    ZEND_RAW_FENTRY("__construct", zim_CefloomBuffer___construct, arginfo_buffer_construct,
                    ZEND_ACC_PRIVATE | ZEND_ACC_CTOR, NULL, NULL)
    ZEND_RAW_FENTRY("getSize", zim_CefloomBuffer_getSize, arginfo_buffer_getSize, ZEND_ACC_PUBLIC, NULL, NULL)
    ZEND_RAW_FENTRY("isValid", zim_CefloomBuffer_isValid, arginfo_buffer_isValid, ZEND_ACC_PUBLIC, NULL, NULL)
    ZEND_RAW_FENTRY("isWritable", zim_CefloomBuffer_isWritable, arginfo_buffer_isWritable, ZEND_ACC_PUBLIC, NULL,
                    NULL)
    ZEND_RAW_FENTRY("read", zim_CefloomBuffer_read, arginfo_buffer_read, ZEND_ACC_PUBLIC, NULL, NULL)
    ZEND_RAW_FENTRY("write", zim_CefloomBuffer_write, arginfo_buffer_write, ZEND_ACC_PUBLIC, NULL, NULL)
    ZEND_FE_END};

// ---- PHP objects held by C++ -----------------------------------------------------------------------

// Release on the PHP thread. A destructor that calls exit() leaves an exception pending, which PHP
// raises when the CEF call returns, so nothing needs to be caught here.
static void release_now(zend_object* o) { OBJ_RELEASE(o); }

void release_later(zend_object* o) {
  if (!g_php_alive) {
    return;  // PHP is gone: the object was freed with the request
  }
  if (on_php_thread()) {
    release_now(o);
    return;
  }
  std::lock_guard<std::mutex> lock(g_release_mutex);
  g_release_queue.push_back(o);
}

void drain_releases() {
  std::vector<zend_object*> queue;
  {
    std::lock_guard<std::mutex> lock(g_release_mutex);
    queue.swap(g_release_queue);
  }
  for (zend_object* o : queue) {
    release_now(o);
  }
}

ProxyBase::ProxyBase(zend_object* o) : obj_(o) { GC_ADDREF(o); }

ProxyBase::~ProxyBase() {
  if (registered_) {
    std::lock_guard<std::mutex> lock(g_proxy_mutex);
    proxy_registry().erase(registered_);
  }
  release_later(obj_);
}

void ProxyBase::register_as(const CefBaseRefCounted* self) {
  registered_ = self;
  std::lock_guard<std::mutex> lock(g_proxy_mutex);
  proxy_registry()[self] = obj_;
}

zend_object* ProxyBase::find(const CefBaseRefCounted* object) {
  if (!object) {
    return nullptr;
  }
  std::lock_guard<std::mutex> lock(g_proxy_mutex);
  auto found = proxy_registry().find(object);
  return found == proxy_registry().end() ? nullptr : found->second;
}

zend_function* find_override(zend_class_entry* ce, zend_class_entry* base, const char* lower_name) {
  zval* zv = zend_hash_str_find(&ce->function_table, lower_name, strlen(lower_name));
  if (!zv) {
    return nullptr;
  }
  zend_function* fn = static_cast<zend_function*>(Z_PTR_P(zv));
  if (!fn || fn->common.scope == base || (fn->common.fn_flags & ZEND_ACC_ABSTRACT)) {
    return nullptr;
  }
  return fn;
}

// ---- calling PHP -----------------------------------------------------------------------------------

namespace {
struct SyncState {
  std::mutex mutex;
  std::condition_variable cv;
  bool started = false;
  bool ran = false;
  bool done = false;
  bool abandoned = false;
};

class SyncTask : public CefTask {
 public:
  SyncTask(std::function<void()> fn, std::shared_ptr<SyncState> state)
      : fn_(std::move(fn)), state_(std::move(state)) {}

  void Execute() override {
    {
      std::lock_guard<std::mutex> lock(state_->mutex);
      if (state_->abandoned) {
        return;
      }
      state_->started = true;
    }
    bool ran = false;
    if (g_php_alive) {
      fn_();
      ran = true;
    }
    {
      std::lock_guard<std::mutex> lock(state_->mutex);
      state_->ran = ran;
      state_->done = true;
    }
    state_->cv.notify_all();
  }

 private:
  std::function<void()> fn_;
  std::shared_ptr<SyncState> state_;
  IMPLEMENT_REFCOUNTING(SyncTask);
};

int64_t sync_wait_limit_ms() {
  static const int64_t limit = [] {
    const char* text = std::getenv("CEFLOOM_SYNC_WAIT_MS");
    long value = text ? std::atol(text) : 0;
    return static_cast<int64_t>(value > 0 ? value : 5000);
  }();
  return limit;
}
}  // namespace

std::atomic<int64_t> g_pump_since_ms{0};

int64_t steady_ms() {
  return std::chrono::duration_cast<std::chrono::milliseconds>(std::chrono::steady_clock::now().time_since_epoch())
      .count();
}

bool run_on_php_thread(const std::function<void()>& fn) {
  if (!g_php_alive) {
    return false;
  }
  if (on_php_thread()) {
    fn();
    return true;
  }
  if (!g_cef_running) {
    return false;
  }
  auto state = std::make_shared<SyncState>();
  if (!CefPostTask(TID_UI, new SyncTask(fn, state))) {
    return false;
  }
  std::unique_lock<std::mutex> lock(state->mutex);
  while (!state->done) {
    state->cv.wait_for(lock, std::chrono::milliseconds(50));
    if (state->done || state->started) {
      continue;
    }
    if (!g_cef_running || !g_php_alive) {
      state->abandoned = true;  // nobody will run it any more
      return false;
    }
    int64_t since = g_pump_since_ms.load();
    if (since != 0 && steady_ms() - since >= sync_wait_limit_ms()) {
      state->abandoned = true;
      std::fprintf(stderr,
                   "cefloom: a CEF thread waited %lld ms for the PHP thread, which sat inside CefDoMessageLoopWork() "
                   "without taking the call; CEF's default is used instead (verified-findings F14)\n",
                   static_cast<long long>(sync_wait_limit_ms()));
      return false;
    }
  }
  return state->ran;
}

bool call_method(zend_object* obj, zend_function* fn, uint32_t argc, zval* argv, zval* retval) {
  if (!g_php_alive || EG(exception)) {
    return false;
  }
  zend_call_known_instance_method(fn, obj, retval, argc, argv);
  return EG(exception) == nullptr;
}

bool call_function(zval* callable, uint32_t argc, zval* argv, zval* retval) {
  if (!g_php_alive || EG(exception)) {
    return false;
  }
  call_user_function(nullptr, nullptr, callable, retval, argc, argv);
  return EG(exception) == nullptr;
}

zval* result_at(zval* rv, size_t count, size_t index) {
  if (!rv || Z_TYPE_P(rv) == IS_UNDEF) {
    return nullptr;
  }
  if (count == 1) {
    return rv;
  }
  if (Z_TYPE_P(rv) != IS_ARRAY) {
    return nullptr;
  }
  return zend_hash_index_find(Z_ARRVAL_P(rv), index);
}

// ---- module hooks ----------------------------------------------------------------------------------

void runtime_minit(int) {
  memcpy(&lib_handlers, &std_object_handlers, sizeof(zend_object_handlers));
  lib_handlers.offset = XtOffsetOf(LibObject, std);
  lib_handlers.free_obj = lib_free;
  lib_handlers.clone_obj = nullptr;

  memcpy(&buffer_handlers, &std_object_handlers, sizeof(zend_object_handlers));
  buffer_handlers.offset = XtOffsetOf(BufferObject, std);
  buffer_handlers.clone_obj = nullptr;

  zend_class_entry ce;
  INIT_NS_CLASS_ENTRY(ce, "Cefloom", "Buffer", buffer_methods);
  ce_buffer = zend_register_internal_class_ex(&ce, nullptr);
  ce_buffer->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE;
  ce_buffer->create_object = buffer_create;
}

void runtime_rinit() {
  set_php_thread();
  g_php_alive = true;
}

void runtime_rshutdown() {
  drain_releases();
  g_php_alive = false;
}

}  // namespace cl
