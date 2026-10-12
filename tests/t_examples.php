<?php
// The programs in examples/ keep working: each runs in its own PHP process (CEF starts once per process) and has to
// print what it promises. They run on the display of the test run (xvfb-run).
require __DIR__ . '/support.php';

$module = (getenv('CEFLOOM_DIST') ?: dirname(__DIR__) . '/build/dist') . '/cefloom.so';
$examples = dirname(__DIR__) . '/examples';
$tmp = sys_get_temp_dir() . '/cefloom-examples-' . getmypid();
mkdir($tmp);
register_shutdown_function(static function () use ($tmp): void { exec('rm -rf ' . escapeshellarg($tmp)); });

/** Run an example; returns [exit code, output]. */
function run_example(string $module, string $tmp, string $script, array $args = []): array
{
    $command = 'env XDG_CACHE_HOME=' . escapeshellarg($tmp . '/cache') . ' timeout -k 5 90 ' . escapeshellarg(PHP_BINARY)
        . ' -d extension=' . escapeshellarg($module) . ' ' . escapeshellarg($script);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    exec($command . ' 2>&1', $lines, $code);
    return [$code, implode("\n", array_filter($lines, fn($l) => !preg_match('/^\[\d+:\d+:\d+\/|ERROR:|WARNING:|INFO:/', $l)))];
}

[$code, $out] = run_example($module, $tmp, "$examples/hello.php");
same(0, $code, 'hello.php ends normally');
check(str_contains($out, 'add=5'), 'hello.php: JavaScript called PHP and got 5');
check(str_contains($out, 'done'), 'hello.php: the program ran to its end');

[$code, $out] = run_example($module, $tmp, "$examples/views.php", ['--close-after=2']);
same(0, $code, 'views.php ends normally');
check(str_contains($out, 'closed'), 'views.php: the window opened and was closed');

$pdf = $tmp . '/page.pdf';
[$code, $out] = run_example($module, $tmp, "$examples/print-pdf.php", ['data:text/html,<h1>Hello</h1>', $pdf, '--landscape']);
same(0, $code, 'print-pdf.php ends normally');
check(str_contains($out, "wrote $pdf"), 'print-pdf.php says it wrote the file');
check(is_file($pdf) && str_starts_with((string) file_get_contents($pdf), '%PDF-'), 'print-pdf.php: the file is a PDF');
[$code] = run_example($module, $tmp, "$examples/print-pdf.php");
same(2, $code, 'print-pdf.php without arguments prints the usage and exits with 2');

[$code, $out] = run_example($module, $tmp, "$examples/cast.php");
same(0, $code, 'cast.php ends normally');
check(str_contains($out, 'cast:CC1AD845') && str_contains($out, 'Cast source') && str_contains($out, 'DIAL source'),
    'cast.php tells Cast and DIAL sources apart');

finish();
