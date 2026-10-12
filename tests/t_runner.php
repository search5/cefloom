<?php
// The test runner (tests/run.sh) itself: what it calls a pass, a failure, a timeout, an expected failure, and what it
// cleans up. It runs fake tests that it is given through TEST_DIR. Nothing of CEF here.
require __DIR__ . '/support.php';

$dir = tmpdir('cefloom-runner');
$tests = [
    't_pass' => '<?php echo "OK (2 checks)\n";',
    't_fail' => '<?php fwrite(STDERR, "FAIL: nope\n"); echo "1 of 2 checks failed\n"; exit(1);',
    't_silent' => '<?php // prints no OK',
    't_fail_then_ok' => '<?php fwrite(STDERR, "FAIL: first\n"); echo "OK (1 checks)\n";',
    't_crash' => '<?php echo "working\n"; exit(139);',
    't_hang' => '<?php sleep(60);',
    't_noexit' => '<?php echo "OK (1 checks)\n"; sleep(60);',
    't_orphan' => '<?php exec("sleep 313 >/dev/null 2>&1 &"); echo "OK (1 checks)\n"; sleep(60);',
    't_xfail_fails' => "<?php\n// xfail: a known problem\nfwrite(STDERR, \"FAIL: known\\n\"); exit(1);",
    't_xfail_passes' => "<?php\n// xfail: a problem that is gone\necho \"OK (1 checks)\\n\";",
];
foreach ($tests as $name => $code) {
    file_put_contents("$dir/$name.php", $code);
}

$run = function (array $names, array $env = []) use ($dir): array {
    $envText = 'TEST_DIR=' . escapeshellarg($dir) . ' TEST_TIMEOUT=3 TEST_EXIT_GRACE=1';
    foreach ($env as $k => $v) {
        $envText .= " $k=" . escapeshellarg($v);
    }
    exec('env -u CEFLOOM_TEST_EXTERNAL_PUMP ' . $envText . ' ' . escapeshellarg(__DIR__ . '/run.sh') . ' ' . implode(' ', $names) . ' 2>&1', $lines, $code);
    return [$code, implode("\n", $lines)];
};

[$code, $out] = $run(['t_pass']);
same(0, $code, 'a passing test: exit code 0');
check(preg_match('/^  pass  t_pass  OK \(2 checks\)$/m', $out) === 1, 'a passing test is reported with its checks');
check(str_contains($out, '1 of 1 tests passed'), 'the summary counts it');

[$code, $out] = $run(['t_fail']);
same(1, $code, 'a failing test: exit code 1');
check(str_contains($out, 'FAIL  t_fail (exit 1)') && str_contains($out, 'FAIL: nope'), 'a failure shows its exit code and its messages');

[$code, $out] = $run(['t_silent']);
check($code === 1 && str_contains($out, 'FAIL  t_silent'), 'a test that prints no OK fails');

[$code, $out] = $run(['t_fail_then_ok']);
check($code === 1 && str_contains($out, 'FAIL  t_fail_then_ok'), 'a FAIL line fails the test even if OK was printed');

[$code, $out] = $run(['t_crash']);
check($code === 1 && str_contains($out, 'FAIL  t_crash (exit 139)'), 'a crash is reported with its exit code');

$t = microtime(true);
[$code, $out] = $run(['t_hang']);
check($code === 1 && str_contains($out, 'FAIL  t_hang (timeout after 3s)'), 'a test that runs too long fails with a timeout');
check(microtime(true) - $t < 15, 'and is stopped when the time is up');

$t = microtime(true);
[$code, $out] = $run(['t_noexit']);
check($code === 0 && str_contains($out, 'pass  t_noexit') && str_contains($out, 'did not exit by itself'), 'a test that prints OK and does not exit passes, with a note');
check(microtime(true) - $t < 15, 'and is stopped after the grace time');

[$code, $out] = $run(['t_orphan']);
usleep(500000);
exec('ps -eo pid,sid,args | grep "[s]leep 313" | grep -v "grep"', $left);
if ($left) { fwrite(STDERR, 'left: ' . implode(' | ', $left) . "\n"); }
same([], $left, 'what a test left running (a child process) is stopped with it');

[$code, $out] = $run(['t_xfail_fails']);
same(0, $code, 'an expected failure that fails: exit code 0');
check(str_contains($out, 'xfail t_xfail_fails  (a known problem)') && str_contains($out, '1 of 1 tests passed'), 'it is reported as xfail and counts as passed');

[$code, $out] = $run(['t_xfail_passes']);
check($code === 1 && str_contains($out, 'unexpectedly passed') && str_contains($out, 'a problem that is gone'), 'an expected failure that passes is a failure');

[$code, $out] = $run(['t_pass', 't_fail', 't_xfail_fails']);
check($code === 1 && str_contains($out, '2 of 3 tests passed'), 'the summary counts the failures among several tests');

// the default is every t_*.php of the directory, in order
[$code, $out] = $run([]);
preg_match_all('/^  (?:pass|FAIL|xfail) +(t_\w+)/m', $out, $m);
$names = $m[1];
$sorted = $names;
sort($sorted);
check(count($names) === count($tests) && $names === $sorted, 'without names every test of the directory runs, in order (' . count($names) . ')');

finish();
