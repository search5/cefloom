/**
 * @file
 * The module: registration of the classes and the hooks of the PHP lifecycle.
 */
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "core.h"
#include "ext/standard/info.h"
#include "include/cef_version.h"

/** Version of the extension, shown by phpinfo() and used as the module version. */
#define CEFLOOM_VERSION "0.1.0"

/**
 * Module startup: set up the runtime and register the classes.
 * On a build that links libcef.so, also configures the CEF API version.
 */
static PHP_MINIT_FUNCTION(cefloom) {
#ifdef CEFLOOM_LAZY_LIBCEF
  // libcef.so is loaded by the first function that needs it (cl_core_load_cef), so that a PHP process that never uses
  // CEF pays nothing for it and a missing runtime does not stop the module from loading.
#else
  // Libcef aborts the process when an object is made before the API version is configured.
  cef_api_hash(CEF_API_VERSION, 0);
#endif
  cl::runtime_minit(module_number);
  cl_register_all();
  cl_core_register();
  return SUCCESS;
}

/** Request startup: let the runtime and the core begin a request. */
static PHP_RINIT_FUNCTION(cefloom) {
  cl::runtime_rinit();
  cl_core_rinit();
  return SUCCESS;
}

/** Request shutdown: shut CEF down if the script forgot, then the runtime. */
static PHP_RSHUTDOWN_FUNCTION(cefloom) {
  cl_core_rshutdown();
  cl::runtime_rshutdown();
  return SUCCESS;
}

/** Print the extension's block of phpinfo(). */
static PHP_MINFO_FUNCTION(cefloom) {
  php_info_print_table_start();
  php_info_print_table_header(2, "cefloom support", "enabled");
  php_info_print_table_row(2, "Extension version", CEFLOOM_VERSION);
  php_info_print_table_row(2, "Built against CEF", CEF_VERSION);
  php_info_print_table_end();
}

/** The module entry of the extension. */
zend_module_entry cefloom_module_entry = {
    STANDARD_MODULE_HEADER,
    "cefloom",
    nullptr,
    PHP_MINIT(cefloom),
    nullptr,
    PHP_RINIT(cefloom),
    PHP_RSHUTDOWN(cefloom),
    PHP_MINFO(cefloom),
    CEFLOOM_VERSION,
    STANDARD_MODULE_PROPERTIES};

#ifdef COMPILE_DL_CEFLOOM
ZEND_GET_MODULE(cefloom)
#endif
