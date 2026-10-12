<?php
// A build that loads libcef.so when it is needed (CEFLOOM_LAZY_LIBCEF) must load without the CEF runtime, so that
// `extension=cefloom.so` in php.ini does not break PHP where the runtime is not installed, and report the missing
// runtime as a PHP error instead of ending the process. Other builds are skipped.
require __DIR__ . '/support.php';

$module = (getenv('CEFLOOM_DIST') ?: dirname(__DIR__) . '/build/dist') . '/cefloom.so';
$dir = sys_get_temp_dir() . '/cefloom-no-runtime-' . getmypid();
mkdir($dir);
register_shutdown_function(static function () use ($dir): void { exec('rm -rf ' . escapeshellarg($dir)); });
copy($module, $dir . '/cefloom.so');   // alone: no libcef.so next to it

$script = $dir . '/probe.php';
file_put_contents($script, <<<'PHP'
<?php
$out = ['loaded' => extension_loaded('cefloom')];
$probe = function (string $name, callable $fn) use (&$out): void {
    try { $fn(); $out[$name] = 'no error'; }
    catch (Throwable $e) { $out[$name] = get_class($e) . ': ' . $e->getMessage(); }
};
$probe('initialize', fn() => Cefloom\Cef::initialize());
$probe('parseJSON', fn() => Cefloom\Cef::parseJSON('{}', 0));
$probe('request', fn() => Cefloom\Request::create());
$probe('struct', function () { $s = new Cefloom\Settings(); $s->locale = 'ko'; if ($s->locale !== 'ko') { throw new Exception('struct broken'); } throw new Exception('struct ok'); });
echo json_encode($out);
PHP);

$command = escapeshellarg(PHP_BINARY) . ' -n -d extension=' . escapeshellarg($dir . '/cefloom.so') . ' ' . escapeshellarg($script) . ' 2>&1';
exec($command, $lines, $code);
$text = implode("\n", $lines);

if (str_contains($text, 'Unable to load dynamic library')) {
    echo "OK (0 checks) skipped: this build links libcef.so, so the module cannot load without the runtime\n";
    exit(0);
}

same(0, $code, 'the process ended normally');
check(!str_contains($text, 'symbol lookup error'), 'no symbol lookup error');
$result = json_decode($text, true);
check(is_array($result), 'the probe printed its result: ' . substr($text, 0, 200));
$result = is_array($result) ? $result : [];
same(true, $result['loaded'] ?? null, 'the module loads without the runtime');
foreach (['initialize', 'parseJSON', 'request'] as $name) {
    check(str_starts_with($result[$name] ?? '', 'Error: The CEF runtime was not found'), "$name reports the missing runtime (" . ($result[$name] ?? 'nothing') . ')');
}
same('Exception: struct ok', $result['struct'] ?? null, 'a settings struct works without the runtime');
finish();
