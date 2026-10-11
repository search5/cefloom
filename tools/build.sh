#!/usr/bin/env bash
# Build the extension (cefloom.so), the subprocess (cefloomsubprocess) and a runnable install directory.
#
#   tools/build.sh              # build into cefloom/build/
#   tools/build.sh --regen      # regenerate the binding first (tools/gen/generate.py)
#
# Inputs (environment variables, defaults for the layout of this workspace):
#   CEF_ROOT              CEF distribution with include/ (default: the cefweaver checkout's build/native/cef)
#   CEFLOOM_WRAPPER_LIB   libcef_dll_wrapper.a built from that distribution
#   CEFLOOM_RUNTIME       a directory with libcef.so, the .pak files, icudtl.dat, locales/ ...
# Output: build/dist/ holds cefloom.so, cefloomsubprocess and the runtime files; run PHP with
#   php -d extension=build/dist/cefloom.so script.php
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
workspace="$(cd "$here/.." && pwd)"
weaver="$workspace/cefweaver_clone/cefweaver"

CEF_ROOT="${CEF_ROOT:-$weaver/build/native/cef}"
CEFLOOM_WRAPPER_LIB="${CEFLOOM_WRAPPER_LIB:-$weaver/build/native/libcef_dll_wrapper/libcef_dll_wrapper.a}"
CEFLOOM_RUNTIME="${CEFLOOM_RUNTIME:-$weaver/build/lib.linux-x86_64-cpython-313/cefweaver}"
BUILD="${BUILD:-$here/build}"
JOBS="${JOBS:-$(nproc)}"
# Compiler temporaries go next to the build: /tmp can be small on some machines.
mkdir -p "$BUILD/tmp"
export TMPDIR="$BUILD/tmp"

if [[ "${1:-}" == "--regen" ]]; then
  python3 -I "$here/tools/gen/generate.py" --cef-root "$CEF_ROOT"
fi

# The extension is built in a copy: phpize writes into the directory it runs in.
mkdir -p "$BUILD/ext" "$BUILD/dist"
rsync -a --exclude '/generated' "$here/ext/" "$BUILD/ext/"
rsync -a --delete "$here/ext/generated/" "$BUILD/ext/generated/"
cd "$BUILD/ext"
if [[ ! -f Makefile || "$here/ext/config.m4" -nt Makefile || "$here/ext/generated/sources.m4" -nt Makefile ]]; then
  phpize >/dev/null
  CEFLOOM_WRAPPER_LIB="$CEFLOOM_WRAPPER_LIB" CEFLOOM_LIBCEF_DIR="$CEFLOOM_RUNTIME" \
    ./configure --with-cefloom="$CEF_ROOT" >/dev/null
fi
make -j"$JOBS" 2>&1 | grep -E "error|Error|warning: unused|undefined reference|\*\*\*" || true
test -f modules/cefloom.so

# The install directory: the extension, the subprocess and the CEF runtime next to each other.
cp -f modules/cefloom.so "$BUILD/dist/cefloom.so"
for f in "$CEFLOOM_RUNTIME"/*; do
  name="$(basename "$f")"
  case "$name" in
    *.py|*.pyi|*.pyx|*.pxd|*.pxi|__pycache__|_cefweaver*|cefsubprocess|py.typed|api|types|ui) continue ;;
  esac
  cp -alf "$f" "$BUILD/dist/$name" 2>/dev/null || cp -rf "$f" "$BUILD/dist/$name"
done
g++ -std=c++20 -fno-rtti -fno-exceptions -O2 -pthread -DWRAPPING_CEF_SHARED -D_FILE_OFFSET_BITS=64 \
  -D__STDC_CONSTANT_MACROS -D__STDC_FORMAT_MACROS -I"$CEF_ROOT" "$here/subprocess/cefloomsubprocess.cc" \
  "$CEFLOOM_WRAPPER_LIB" -L"$BUILD/dist" -lcef -Wl,-rpath,'$ORIGIN' -o "$BUILD/dist/cefloomsubprocess"
patchelf --set-rpath '$ORIGIN' "$BUILD/dist/cefloom.so"
echo "built: $BUILD/dist"
