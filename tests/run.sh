#!/usr/bin/env bash
# Run the tests: tests/run.sh [name ...]   (default: every t_*.php)
# Every test runs in its own PHP process (CEF can be started once per process) under a virtual X server.
set -uo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
dist="${CEFLOOM_DIST:-$here/../build/dist}"
cd "$here"
tests=("$@")
[[ ${#tests[@]} -eq 0 ]] && tests=($(ls t_*.php | sed 's/\.php$//'))
failed=0
for t in "${tests[@]}"; do
  out="$(env -u WAYLAND_DISPLAY timeout "${TEST_TIMEOUT:-120}" xvfb-run -a php -d extension="$dist/cefloom.so" "$t.php" 2>&1)"
  code=$?
  if [[ $code -eq 0 && "$out" == *"OK ("* ]]; then
    printf '  pass  %s  %s\n' "$t" "$(grep -o 'OK ([0-9]* checks)' <<<"$out" | tail -1)"
  else
    failed=$((failed + 1))
    printf '  FAIL  %s (exit %s)\n' "$t" "$code"
    grep -v "ERROR:\|WARNING:\|INFO:" <<<"$out" | head -15 | sed 's/^/        /'
  fi
done
echo "$((${#tests[@]} - failed)) of ${#tests[@]} tests passed"
exit $((failed > 0))
