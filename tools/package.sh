#!/usr/bin/env bash
# Make the release files from a finished build (tools/build.sh):
#
#   tools/package.sh                 # reads build/dist, writes build/release/
#
# Two kinds of files are made:
#   php_cefloom-<version>_php<X.Y>-<arch>-linux-<libc>-nts.zip   the extension, for PIE (pre-packaged-binary)
#   cefloom-runtime-<CEF version>-linux-<arch>.tar.gz            the CEF runtime, for bin/cefloom-runtime
# and a .sha256 file next to each. Attach them to a GitHub release: PIE looks for the first kind among the assets
# of the release of the package.
#
# The name of the first kind follows PIE's rule (src/Platform/PrePackagedBinaryAssetName.php of PIE 1.5.2): lower
# case, php_<extension>-<version>_php<major.minor>-<arch>-<os>-<libc>[-debug](-nts|-zts), .zip or .tgz, and
# cefloom.so at the top of the archive.
#
# Inputs (environment variables): CEFLOOM_DIST (default build/dist), OUT (default build/release), PHP (default php).
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
dist="${CEFLOOM_DIST:-$here/build/dist}"
out="${OUT:-$here/build/release}"
php_bin="${PHP:-php}"

for f in cefloom.so cefloomsubprocess libcef.so icudtl.dat resources.pak v8_context_snapshot.bin; do
  [[ -f "$dist/$f" ]] || { echo "missing $dist/$f: run tools/build.sh first" >&2; exit 1; }
done
[[ -d "$dist/locales" ]] || { echo "missing $dist/locales" >&2; exit 1; }

version="$(sed -n 's/^#define CEFLOOM_VERSION "\(.*\)"/\1/p' "$here/ext/src/cefloom.cc")"
[[ -n "$version" ]] || { echo "CEFLOOM_VERSION not found in ext/src/cefloom.cc" >&2; exit 1; }

# The module must be the lazy build: only that one loads without the runtime, which PIE needs.
if readelf -d "$dist/cefloom.so" | grep -q 'Shared library: \[libcef.so\]'; then
  echo "$dist/cefloom.so links libcef.so: build it without CEFLOOM_LAZY_LIBCEF=0" >&2
  exit 1
fi

php_mm="$("$php_bin" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
[[ "$("$php_bin" -r 'echo (int) PHP_ZTS;')" == "0" ]] || { echo "this PHP is thread safe (ZTS): not supported yet" >&2; exit 1; }
[[ "$("$php_bin" -r 'echo (int) PHP_DEBUG;')" == "0" ]] || { echo "this PHP is a debug build: not packaged" >&2; exit 1; }
arch="$(uname -m)"
case "$arch" in
  x86_64) ;;
  aarch64) arch=arm64 ;;
  *) echo "unsupported architecture: $arch" >&2; exit 1 ;;
esac
getconf GNU_LIBC_VERSION > /dev/null 2>&1 || { echo "this system is not glibc: CEF needs glibc" >&2; exit 1; }
cef_version="$("$php_bin" -d "extension=$dist/cefloom.so" -r 'echo Cefloom\Cef::cefVersion();')"
cef_short="${cef_version%%+*}"

mkdir -p "$out"
rm -f "$out"/php_cefloom-* "$out"/cefloom-runtime-*

# ---- the extension
extension_zip="php_cefloom-${version}_php${php_mm}-${arch}-linux-glibc-nts.zip"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
cp "$dist/cefloom.so" "$stage/cefloom.so"
(cd "$stage" && zip -q -X "$out/$extension_zip" cefloom.so)

# ---- the runtime
runtime_tar="cefloom-runtime-${cef_short}-linux-${arch}.tar.gz"
mkdir -p "$stage/runtime"
for f in cefloomsubprocess libcef.so libvk_swiftshader.so libvulkan.so.1 vk_swiftshader_icd.json icudtl.dat \
         resources.pak chrome_100_percent.pak chrome_200_percent.pak v8_context_snapshot.bin; do
  [[ -f "$dist/$f" ]] && cp "$dist/$f" "$stage/runtime/$f"
done
mkdir -p "$stage/runtime/locales"
cp "$dist"/locales/*.pak "$stage/runtime/locales/"
"$php_bin" -r '
  $dir = $argv[1];
  $files = [];
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) { $files[] = substr($f->getPathname(), strlen($dir) + 1); }
  sort($files);
  file_put_contents($dir . "/cefloom-runtime.json", json_encode(["cef" => $argv[2], "arch" => $argv[3], "files" => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$stage/runtime" "$cef_version" "$arch"
# The entries are named one by one: an entry "." (from `tar ... .`) cannot be unpacked by PHP's PharData.
tar -C "$stage/runtime" --sort=name --owner=0 --group=0 --numeric-owner -czf "$out/$runtime_tar" \
  $(cd "$stage/runtime" && ls -A)

(cd "$out" && for f in "$extension_zip" "$runtime_tar"; do sha256sum "$f" > "$f.sha256"; done)

echo "made in $out:"
(cd "$out" && stat -c '  %12s  %n' *)
