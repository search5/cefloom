#!/usr/bin/env bash
# Build the extension (cefloom.so), the subprocess (cefloomsubprocess) and a runnable install directory.
#
#   tools/build.sh              # build into cefloom/build/
#   tools/build.sh --regen      # regenerate the binding first (tools/gen/generate.py)
#   CEFLOOM_LAZY_LIBCEF=0 tools/build.sh   # link libcef.so instead of loading it when needed (see below)
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

# The module does not link libcef.so by default: it loads it from the CEF runtime directory when it is needed, so it
# also loads where the runtime is not installed and costs a PHP process that does not use CEF almost nothing
# (llm-wiki, verified-findings F16). CEFLOOM_LAZY_LIBCEF=0 links it instead. The mode is a compiler flag that make
# does not track: a build directory is rebuilt from scratch when the mode changes.
if [[ "${CEFLOOM_LAZY_LIBCEF-1}" == "0" ]]; then export CEFLOOM_LAZY_LIBCEF=""; else export CEFLOOM_LAZY_LIBCEF=1; fi
# CEFLOOM_COVERAGE=1 builds a module that counts which functions run (tools/coverage.sh).
export CEFLOOM_COVERAGE="${CEFLOOM_COVERAGE:-}"
mode_file="$BUILD/ext.mode"
mode="lazy=$CEFLOOM_LAZY_LIBCEF coverage=$CEFLOOM_COVERAGE"
mkdir -p "$BUILD"
if [[ -d "$BUILD/ext" && "$(cat "$mode_file" 2>/dev/null || true)" != "$mode" ]]; then
  rm -rf "$BUILD/ext"
fi
printf '%s' "$mode" > "$mode_file"

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
# A failed compile must stop the build: an older cefloom.so from a previous run would otherwise be installed.
make -j"$JOBS" > "$BUILD/make.log" 2>&1 || { grep -E "error|Error|undefined reference|\*\*\*" "$BUILD/make.log" | head -20; echo "build failed (see $BUILD/make.log)" >&2; exit 1; }
grep -E "warning: unused" "$BUILD/make.log" || true
test -f modules/cefloom.so

# The install directory: the extension, the subprocess and the CEF runtime next to each other.
cp -f modules/cefloom.so "$BUILD/dist/cefloom.so"
for f in "$CEFLOOM_RUNTIME"/*; do
  name="$(basename "$f")"
  case "$name" in
    *.py|*.pyi|*.pyx|*.pxd|*.pxi|__pycache__|_cefweaver*|cefsubprocess|py.typed|api|types|ui) continue ;;
  esac
  # Hard links when BUILD is on the same file system as the runtime; else a copy. A failed hard link attempt leaves a
  # half made directory behind: remove it, or the copy would go into it ("locales/locales").
  cp -alf "$f" "$BUILD/dist/$name" 2>/dev/null || { rm -rf "$BUILD/dist/$name"; cp -rf "$f" "$BUILD/dist/$name"; }
done
# No stack protector: Chromium's zygote changes the stack guard value in every process it forks
# (--change-stack-guard-on-fork), so main() would fail its canary check when the process exits.
g++ -std=c++20 -fno-stack-protector -fno-rtti -fno-exceptions -O2 -pthread -DWRAPPING_CEF_SHARED -D_FILE_OFFSET_BITS=64 \
  -D__STDC_CONSTANT_MACROS -D__STDC_FORMAT_MACROS -I"$CEF_ROOT" "$here/subprocess/cefloomsubprocess.cc" \
  "$CEFLOOM_WRAPPER_LIB" -L"$BUILD/dist" -lcef -Wl,-rpath,'$ORIGIN' -o "$BUILD/dist/cefloomsubprocess"
patchelf --set-rpath '$ORIGIN' "$BUILD/dist/cefloom.so"
echo "built: $BUILD/dist"
