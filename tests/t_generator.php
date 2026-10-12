<?php
// The unit tests of the binding generator (tests/gen/test_generator.py, python3 -m unittest). They read made-up headers and, when
// they are there, the real CEF headers, and need no build and no runtime.
require __DIR__ . '/support.php';

$python = trim((string) shell_exec('command -v python3'));
check($python !== '', 'python3 is available');
exec(escapeshellarg($python) . ' -m unittest discover -s ' . escapeshellarg(__DIR__ . '/gen') . ' -v 2>&1', $lines, $code);
$text = implode("\n", $lines);
preg_match('/Ran (\d+) tests/', $text, $m);
$ran = (int) ($m[1] ?? 0);
check($code === 0, "the generator tests pass ($ran tests)" . ($code === 0 ? '' : "\n" . $text));
check($ran >= 25, "and they ran ($ran tests)");
check(substr_count($text, 'skipped') === 0, 'none was skipped: the real CEF headers were found');
finish();
