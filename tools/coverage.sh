#!/usr/bin/env bash
# Which functions of the extension do the tests run?
#
#   tools/coverage.sh                 # builds build-coverage/, runs the tests twice (polling and external pump), reports
#   BUILD=/some/dir tools/coverage.sh
#
# The build counts function and line executions with gcov (CEFLOOM_COVERAGE=1: slow, not for use). The report is
# printed and written to <build>/coverage.json. See llm-wiki/pages/analyses/test-coverage.md for what it found.
set -uo pipefail
here="$(cd "$(dirname "$0")/.." && pwd)"
build="${BUILD:-$here/build-coverage}"

CEFLOOM_COVERAGE=1 BUILD="$build" "$here/tools/build.sh" > "$build.log" 2>&1 || { tail -20 "$build.log"; exit 1; }
find "$build/ext" -name '*.gcda' -delete
# The tests run one after another, in both modes of the message loop: the counts of the runs add up.
CEFLOOM_DIST="$build/dist" "$here/tests/run.sh" | tail -3
CEFLOOM_TEST_EXTERNAL_PUMP=1 CEFLOOM_DIST="$build/dist" "$here/tests/run.sh" | tail -3
python3 -I "$here/tools/coverage_report.py" "$build" --json "$build/coverage.json"
