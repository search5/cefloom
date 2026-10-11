// The module: registration of the classes and the hooks of the PHP lifecycle.
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "core.h"
#include "ext/standard/info.h"
#include "include/cef_version.h"

#define CEFLOOM_VERSION "0.1.0"

static PHP_MINIT_FUNCTION(cefloom) {
  // Libcef aborts the process when an object is made before the API version is configured.
  cef_api_hash(CEF_API_VERSION, 0);
  cl::runtime_minit(module_number);
  cl_register_all();
  cl_core_register();
  return SUCCESS;
}

static PHP_RINIT_FUNCTION(cefloom) {
  cl::runtime_rinit();
  cl_core_rinit();
  return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(cefloom) {
  cl_core_rshutdown();
  cl::runtime_rshutdown();
  return SUCCESS;
}

static PHP_MINFO_FUNCTION(cefloom) {
  php_info_print_table_start();
  php_info_print_table_header(2, "cefloom support", "enabled");
  php_info_print_table_row(2, "Extension version", CEFLOOM_VERSION);
  php_info_print_table_row(2, "Built against CEF", CEF_VERSION);
  php_info_print_table_end();
}

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
