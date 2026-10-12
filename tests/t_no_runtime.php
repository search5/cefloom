<?php
// A build that loads libcef.so when it is needed (CEFLOOM_LAZY_LIBCEF) must load without the CEF runtime, so that
// `extension=cefloom.so` in php.ini does not break PHP where the runtime is not installed, and report the missing
// runtime as a PHP error instead of ending the process. It also finds a runtime in the user's data directory.
// Other builds are skipped.
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

// The probe runs with its own home and data directory, and without CEFLOOM_HOME, so that nothing of this machine leaks in.
$environment = 'env -u CEFLOOM_HOME HOME=' . escapeshellarg($dir . '/home') . ' XDG_DATA_HOME=' . escapeshellarg($dir . '/data');
mkdir($dir . '/home');
$command = $environment . ' ' . escapeshellarg(PHP_BINARY) . ' -n -d extension=' . escapeshellarg($dir . '/cefloom.so') . ' ' . escapeshellarg($script) . ' 2>&1';
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

// The runtime in the data directory of the user: <data>/cefloom/runtime/<CEF version>/libcef.so is found.
$userDir = trim((string) shell_exec($environment . ' ' . escapeshellarg(PHP_BINARY) . ' -n -d extension=' . escapeshellarg($dir . '/cefloom.so')
    . ' -r ' . escapeshellarg('echo Cefloom\\Cef::userRuntimeDirectory();')));
check(str_starts_with($userDir, $dir . '/data/cefloom/runtime/'), "the user runtime directory is inside XDG_DATA_HOME ($userDir)");
$libcef = dirname($module) . '/libcef.so';
if (is_readable($libcef) && str_starts_with($userDir, $dir)) {
    mkdir($userDir, 0777, true);
    symlink($libcef, $userDir . '/libcef.so');
    $found = trim((string) shell_exec($environment . ' ' . escapeshellarg(PHP_BINARY) . ' -n -d extension=' . escapeshellarg($dir . '/cefloom.so')
        . ' -r ' . escapeshellarg('echo Cefloom\\Cef::runtimeDirectory(), "|", json_encode(Cefloom\\Cef::parseJSON("[1]", 0)->getType() !== null);') . ' 2>&1'));
    same($userDir . '|true', $found, 'a runtime in the user data directory is found and works');
}
finish();
