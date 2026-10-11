#!/usr/bin/env bash
# CEF C API를 PHP FFI로 구동하는 사전 검증을 처음부터 다시 수행한다.
# 사용법: run.sh [windowed|osr]
# 환경 변수: RT(런타임 디렉터리), CEF_ROOT(CEF 배포본, 헤더용), OUT(산출물 디렉터리), TIMEOUT(초)
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
ws="$(cd "$here/../../.." && pwd)"
RT="${RT:-$ws/cefweaver_clone/cefweaver/build/lib.linux-x86_64-cpython-313/cefweaver}"
CEF_ROOT="${CEF_ROOT:-$ws/cefweaver_clone/cefweaver/build/native/cef}"
OUT="${OUT:-$(mktemp -d)}"
mkdir -p "$OUT/work"

# 1. 헤더 전처리: CEF 헤더에서 온 줄만 남겨 FFI 선언으로 만든다.
cat > "$OUT/all.h" <<'H'
#include "include/capi/cef_app_capi.h"
#include "include/capi/cef_client_capi.h"
#include "include/capi/cef_browser_capi.h"
#include "include/capi/cef_life_span_handler_capi.h"
#include "include/capi/cef_load_handler_capi.h"
#include "include/capi/cef_display_handler_capi.h"
#include "include/capi/cef_render_handler_capi.h"
#include "include/capi/cef_browser_process_handler_capi.h"
#include "include/capi/cef_frame_capi.h"
#include "include/capi/cef_command_line_capi.h"
#include "include/cef_api_hash.h"
#include "include/cef_version_info.h"
H
gcc -E -I"$CEF_ROOT" -D__linux__ "$OUT/all.h" -o "$OUT/all_m.i"
python3 -I "$here/mkcdef.py" "$OUT/all_m.i" "$OUT/cef_body.h"
printf 'typedef int wchar_t;\ntypedef unsigned short char16_t;\ntypedef int pid_t;\ntypedef long time_t;\n' > "$OUT/pre.h"
cat "$OUT/pre.h" "$OUT/cef_body.h" > "$OUT/cef.h"

# 2. 참조 카운트와 펌프 예약 콜백을 담당하는 네이티브 shim
gcc -shared -fPIC -O1 -o "$OUT/librefshim.so" "$here/refshim.c"

# 3. 실행 (Linux에서는 가상 X 서버, Wayland 변수 해제)
env -u WAYLAND_DISPLAY REFSHIM="$OUT/librefshim.so" MODE="${1:-windowed}" TIMEOUT="${TIMEOUT:-30}" \
  xvfb-run -a php -d ffi.enable=1 "$here/verify.php" "$RT" "$OUT/cef.h" "$OUT/work"
