/**
 * @file
 * Stand-ins for the three logging functions of libcef.so (only in a build with CEFLOOM_LAZY_LIBCEF, where libcef.so is loaded
 * when it is needed, not linked).
 *
 * The wrapper library of CEF keeps the addresses of three functions of libcef.so in a table (base/cef_logging.h). An
 * address taken that way is looked up by the dynamic linker when cefloom.so is loaded, before libcef.so can be, and
 * the module would not load without the runtime. These stand-ins live in this module (hidden, so libcef.so never sees
 * them), which keeps the lookup local, and forward to libcef.so once it has been loaded.
 *
 * This file includes no header of CEF on purpose: the functions are declared there with another visibility.
 */
#ifdef CEFLOOM_LAZY_LIBCEF

#include <dlfcn.h>

#include <cstddef>

namespace {

/** The handle of libcef.so; null until cl_lazy_set_libcef() is called. */
void* g_libcef = nullptr;

/** Return the symbol @p name of libcef.so as @p F, or null if libcef.so is not loaded or lacks it. */
template <class F>
F lookup(const char* name) {
  return g_libcef ? reinterpret_cast<F>(dlsym(g_libcef, name)) : nullptr;
}

}  // namespace

/** Tell the stand-ins where libcef.so is; call after dlopen(). */
void cl_lazy_set_libcef(void* handle) { g_libcef = handle; }

extern "C" {

/** Return the minimum log level of libcef.so; 99 (log nothing) before it is loaded. */
__attribute__((visibility("hidden"))) int cef_get_min_log_level(void) {
  auto f = lookup<int (*)(void)>("cef_get_min_log_level");
  return f ? f() : 99;  // nothing is logged before the runtime is there
}

/** Return the verbose log level of libcef.so for the file; 0 before it is loaded. */
__attribute__((visibility("hidden"))) int cef_get_vlog_level(const char* file_start, size_t n) {
  auto f = lookup<int (*)(const char*, size_t)>("cef_get_vlog_level");
  return f ? f(file_start, n) : 0;
}

/** Forward a log message to libcef.so; dropped before it is loaded. */
__attribute__((visibility("hidden"))) void cef_log(const char* file, int line, int severity, const char* message) {
  auto f = lookup<void (*)(const char*, int, int, const char*)>("cef_log");
  if (f) {
    f(file, line, severity, message);
  }
}

}  // extern "C"

#endif  // CEFLOOM_LAZY_LIBCEF
