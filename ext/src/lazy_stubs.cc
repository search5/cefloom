// Only in a build with CEFLOOM_LAZY_LIBCEF (libcef.so is loaded when it is needed, not linked).
//
// The wrapper library of CEF keeps the addresses of three functions of libcef.so in a table (base/cef_logging.h). An
// address taken that way is looked up by the dynamic linker when cefloom.so is loaded, before libcef.so can be, and
// the module would not load without the runtime. These stand-ins live in this module (hidden, so libcef.so never sees
// them), which keeps the lookup local, and forward to libcef.so once it has been loaded.
//
// This file includes no header of CEF on purpose: the functions are declared there with another visibility.
#ifdef CEFLOOM_LAZY_LIBCEF

#include <dlfcn.h>

#include <cstddef>

namespace {

void* g_libcef = nullptr;

template <class F>
F lookup(const char* name) {
  return g_libcef ? reinterpret_cast<F>(dlsym(g_libcef, name)) : nullptr;
}

}  // namespace

void cl_lazy_set_libcef(void* handle) { g_libcef = handle; }

extern "C" {

__attribute__((visibility("hidden"))) int cef_get_min_log_level(void) {
  auto f = lookup<int (*)(void)>("cef_get_min_log_level");
  return f ? f() : 99;  // nothing is logged before the runtime is there
}

__attribute__((visibility("hidden"))) int cef_get_vlog_level(const char* file_start, size_t n) {
  auto f = lookup<int (*)(const char*, size_t)>("cef_get_vlog_level");
  return f ? f(file_start, n) : 0;
}

__attribute__((visibility("hidden"))) void cef_log(const char* file, int line, int severity, const char* message) {
  auto f = lookup<void (*)(const char*, int, int, const char*)>("cef_log");
  if (f) {
    f(file, line, severity, message);
  }
}

}  // extern "C"

#endif  // CEFLOOM_LAZY_LIBCEF
