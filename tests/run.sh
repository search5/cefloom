#!/usr/bin/env bash
# Run the tests: tests/run.sh [name ...]   (default: every t_*.php)
# CEFLOOM_TEST_EXTERNAL_PUMP=1 tests/run.sh runs them with the external message pump (Settings::externalMessagePump).
# TEST_DIR=<directory> runs the t_*.php of another directory (the tests of this runner use it).
# A test whose file has a line `// xfail: <reason>` is expected to fail (a known problem): it is reported as xfail and
# counts as passed; if it passes, that is a failure ("unexpectedly passed"), so that a fix is noticed.
# Tests run one after another, each in its own PHP process (CEF can be started once per process) under a virtual X server.
# Each test gets its own session, so a timeout or a process that does not exit after printing OK is cleaned up completely.
set -uo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
dist="$(cd "${CEFLOOM_DIST:-$here/../build/dist}" && pwd)"
export CEFLOOM_DIST="$dist"   # tests that need the module file itself (t_no_runtime)
limit="${TEST_TIMEOUT:-120}"      # seconds a test may run
grace="${TEST_EXIT_GRACE:-10}"    # seconds a test may take to exit after it printed OK
cd "${TEST_DIR:-$here}"
tests=("$@")
[[ ${#tests[@]} -eq 0 ]] && tests=($(ls t_*.php | sed 's/\.php$//'))
log="$(mktemp)"
trap 'rm -f "$log"' EXIT
failed=0
for t in "${tests[@]}"; do
  : >"$log"
  setsid env -u WAYLAND_DISPLAY xvfb-run -a php -d extension="$dist/cefloom.so" "$t.php" >"$log" 2>&1 &
  sid=$!
  start=$SECONDS
  ok_at=""
  while kill -0 "$sid" 2>/dev/null; do
    if [[ -z "$ok_at" ]] && grep -q '^OK (' "$log"; then ok_at=$SECONDS; fi
    if [[ -n "$ok_at" ]] && ((SECONDS - ok_at >= grace)); then break; fi
    if ((SECONDS - start >= limit)); then break; fi
    sleep 0.5
  done
  # Stop whatever is left of this test, including CEF subprocesses and the X server. This comes before wait,
  # which would otherwise block for good on a test that does not exit.
  alive=0; kill -0 "$sid" 2>/dev/null && alive=1
  pkill -TERM -s "$sid" 2>/dev/null
  sleep 0.5
  pkill -KILL -s "$sid" 2>/dev/null
  wait "$sid" 2>/dev/null
  code=$?
  ((alive)) && code=124
  out="$(cat "$log")"
  xfail="$(grep -m1 '^// xfail: ' "$t.php" | sed 's#^// xfail: ##')"
  if [[ "$out" == *$'\nOK ('* || "$out" == "OK ("* ]] && ! grep -q '^FAIL' <<<"$out"; then
    if [[ -n "$xfail" ]]; then
      failed=$((failed + 1))
      printf '  FAIL  %s (unexpectedly passed; it was expected to fail: %s. Remove its xfail line)\n' "$t" "$xfail"
    else
      note=""
      [[ -n "$ok_at" && $code -ne 0 ]] && note="  (process did not exit by itself, stopped)"
      printf '  pass  %s  %s%s\n' "$t" "$(grep -o 'OK ([0-9]* checks)' <<<"$out" | tail -1)" "$note"
    fi
  elif [[ -n "$xfail" ]]; then
    printf '  xfail %s  (%s)\n' "$t" "$xfail"
  else
    failed=$((failed + 1))
    reason="exit $code"; ((SECONDS - start >= limit)) && reason="timeout after ${limit}s"
    printf '  FAIL  %s (%s)\n' "$t" "$reason"
    grep -v "ERROR:\|WARNING:\|INFO:" <<<"$out" | head -15 | sed 's/^/        /'
  fi
done
echo "$((${#tests[@]} - failed)) of ${#tests[@]} tests passed"
exit $((failed > 0))
