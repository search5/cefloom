dnl config.m4 for the cefloom extension
dnl
dnl   phpize && ./configure --with-cefloom=<CEF distribution> && make
dnl
dnl The environment variables CEFLOOM_WRAPPER_LIB (libcef_dll_wrapper.a) and CEFLOOM_LIBCEF_DIR (the
dnl directory of libcef.so) tell the linker where CEF's libraries are. tools/build.sh sets them.

PHP_ARG_WITH([cefloom],
  [for CEF support],
  [AS_HELP_STRING([--with-cefloom=DIR],
    [Path of the CEF distribution (the directory that holds include/)])])

if test "$PHP_CEFLOOM" != "no"; then
  PHP_REQUIRE_CXX()

  if test ! -f "$PHP_CEFLOOM/include/cef_app.h"; then
    AC_MSG_ERROR([$PHP_CEFLOOM/include/cef_app.h not found: --with-cefloom must name a CEF distribution])
  fi
  if test -z "$CEFLOOM_WRAPPER_LIB" || test ! -f "$CEFLOOM_WRAPPER_LIB"; then
    AC_MSG_ERROR([set CEFLOOM_WRAPPER_LIB to libcef_dll_wrapper.a])
  fi
  if test -z "$CEFLOOM_LIBCEF_DIR" || test ! -f "$CEFLOOM_LIBCEF_DIR/libcef.so"; then
    AC_MSG_ERROR([set CEFLOOM_LIBCEF_DIR to the directory of libcef.so])
  fi

  m4_include([generated/sources.m4])
  CEFLOOM_SOURCES="src/cefloom.cc src/core.cc src/bridge.cc src/async.cc runtime/cl_runtime.cc $CL_GENERATED_SOURCES"

  dnl The wrapper library of CEF is built without exceptions and RTTI, so are we (the classes we derive from
  dnl CEF's refer to its type information).
  CEFLOOM_CXXFLAGS="-std=c++20 -g0 -O1 -pipe -fno-rtti -fno-exceptions -fPIC -DWRAPPING_CEF_SHARED -D_FILE_OFFSET_BITS=64 -D__STDC_CONSTANT_MACROS -D__STDC_FORMAT_MACROS -Wno-unused-parameter -Wno-unused-variable -Wno-unused-but-set-variable -Wno-deprecated-declarations -Wno-missing-field-initializers"

  if test -n "$CEFLOOM_COVERAGE"; then
    dnl A build that counts which functions the tests run (tools/coverage.sh); slower, not for use.
    CEFLOOM_CXXFLAGS="$CEFLOOM_CXXFLAGS -O0 -g --coverage -fprofile-update=atomic"
  fi
  if test -n "$CEFLOOM_LAZY_LIBCEF"; then
    CEFLOOM_CXXFLAGS="$CEFLOOM_CXXFLAGS -DCEFLOOM_LAZY_LIBCEF"
    CEFLOOM_SOURCES="$CEFLOOM_SOURCES src/lazy_stubs.cc"
  fi

  PHP_ADD_INCLUDE([$PHP_CEFLOOM])
  PHP_ADD_INCLUDE([$srcdir/runtime])
  PHP_ADD_INCLUDE([$srcdir/generated])
  PHP_ADD_INCLUDE([$srcdir/src])
  PHP_ADD_BUILD_DIR([$ext_builddir/src])
  PHP_ADD_BUILD_DIR([$ext_builddir/runtime])
  PHP_ADD_BUILD_DIR([$ext_builddir/generated])

  PHP_NEW_EXTENSION(cefloom, $CEFLOOM_SOURCES, $ext_shared,, $CEFLOOM_CXXFLAGS, yes)

  if test -n "$CEFLOOM_LAZY_LIBCEF"; then
    dnl libcef.so is not linked: the module loads it from its own directory when it is needed
    CEFLOOM_SHARED_LIBADD="$CEFLOOM_WRAPPER_LIB"
  else
    CEFLOOM_SHARED_LIBADD="$CEFLOOM_WRAPPER_LIB -L$CEFLOOM_LIBCEF_DIR -lcef -Wl,-rpath,$CEFLOOM_LIBCEF_DIR"
  fi
  if test -n "$CEFLOOM_COVERAGE"; then
    CEFLOOM_SHARED_LIBADD="$CEFLOOM_SHARED_LIBADD --coverage"
  fi
  PHP_SUBST(CEFLOOM_SHARED_LIBADD)
  AC_DEFINE(HAVE_CEFLOOM, 1, [Whether the cefloom extension is enabled])
fi
