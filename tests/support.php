<?php
// Helpers of the cefloom tests. A test is a PHP script that prints "OK" as its last line and exits with 0.
declare(strict_types=1);

use Cefloom\Cef;

$GLOBALS['cl_failures'] = 0;
$GLOBALS['cl_checks'] = 0;

function check(bool $condition, string $what): void
{
    $GLOBALS['cl_checks']++;
    if (!$condition) {
        $GLOBALS['cl_failures']++;
        fwrite(STDERR, "FAIL: $what\n");
    }
}

function same(mixed $expected, mixed $actual, string $what): void
{
    check($expected === $actual, $what . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

function throws(callable $fn, string $class, string $what): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        check($e instanceof $class, "$what (threw " . get_class($e) . ': ' . $e->getMessage() . ')');
        return;
    }
    check(false, "$what (nothing thrown)");
}

/** Start CEF in a way that works on a machine without a display (the tests run under xvfb-run). */
function start_cef(?Cefloom\Settings $settings = null, ?Cefloom\App $app = null): void
{
    Cef::addSwitch('ozone-platform', 'x11');
    Cef::addSwitch('disable-gpu');
    // Every test process gets its own cache directory: Chromium locks it, and tests run in parallel.
    $settings ??= new Cefloom\Settings();
    if (getenv('CEFLOOM_TEST_EXTERNAL_PUMP')) {
        $settings->externalMessagePump = 1;   // run the tests in the event driven mode too (see run.sh)
    }
    if ($settings->rootCachePath === '') {
        $base = getenv('CEFLOOM_TEST_TMP') ?: dirname(__DIR__) . '/build/tmp';
        $dir = $base . '/cache-' . getmypid();
        @mkdir($dir, 0777, true);
        register_shutdown_function(static function () use ($dir): void {
            exec('rm -rf ' . escapeshellarg($dir));
        });
        $settings->rootCachePath = $dir;
    }
    if (!Cef::initialize($settings, $app)) {
        fwrite(STDERR, "FAIL: Cef::initialize() returned false\n");
        exit(1);
    }
}

/** Run the CEF loop for $seconds (the loop of a test that waits for something that is not a condition). */
function pump(float $seconds): void
{
    $end = microtime(true) + $seconds;
    while (microtime(true) < $end) {
        Cef::doMessageLoopWork();
        Cef::wait(5);
    }
}

/** Make input on the display with xdotool (the XTEST extension of the X server): xdo('mousemove', '10', '20'). */
function xdo(string ...$args): void
{
    exec('xdotool ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, 'xdotool failed: ' . implode(' ', $out) . "\n");
    }
}

/** True when xdotool and a display are there. */
function can_make_input(): bool
{
    exec('command -v xdotool', $found, $status);
    return $status === 0 && (bool) getenv('DISPLAY') && !getenv('CEFLOOM_TEST_EXTERNAL_PUMP');
}

/** A new directory for this test, removed when the test ends. */
function tmpdir(string $prefix = 'cefloom-test'): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($dir, 0777, true);
    register_shutdown_function(static function () use ($dir): void { exec('rm -rf ' . escapeshellarg($dir)); });
    return $dir;
}

/**
 * Run a PHP script of its own with the extension loaded (CEF starts once per process). Returns [exit code, output].
 * $module is the cefloom.so to load: CEFLOOM_DIST or the build directory.
 */
function run_php(string $script, array $args = [], array $env = [], int $timeout = 90, ?string $module = null): array
{
    $module ??= (getenv('CEFLOOM_DIST') ?: dirname(__DIR__) . '/build/dist') . '/cefloom.so';
    $envText = '';
    foreach ($env as $name => $value) {
        $envText .= ($value === null ? ' -u ' . escapeshellarg($name) : ' ' . $name . '=' . escapeshellarg((string) $value));
    }
    $command = 'env' . $envText . ' timeout -k 5 ' . $timeout . ' ' . escapeshellarg(PHP_BINARY) . ' -d extension=' . escapeshellarg($module)
        . ' ' . escapeshellarg($script);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    exec($command . ' 2>&1', $lines, $code);
    $kept = array_filter($lines, fn($l) => !preg_match('/^\[\d+:\d+:\d+\/|ERROR:|WARNING:|INFO:/', $l));
    return [$code, implode("\n", $kept)];
}

/**
 * Start the local HTTP server of the tests (tests/fixtures/server.php) on a free port of 127.0.0.1.
 * Returns the base URL ("http://127.0.0.1:PORT"); the server is stopped when the test ends.
 */
function start_http_server(): string
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fixtures/server.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        __DIR__,
        ['PHP_CLI_SERVER_WORKERS' => '8'] + getenv()
    );
    register_shutdown_function(static function () use ($process): void {
        $status = proc_get_status($process);
        if ($status['running']) {
            // the built-in server forks workers: stop the group of the process
            exec('pkill -TERM -P ' . (int) $status['pid'] . ' 2>/dev/null');
            proc_terminate($process);
        }
        proc_close($process);
    });
    for ($i = 0; $i < 100; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $e, $s, 0.1);
        if ($socket) {
            fclose($socket);
            return "http://127.0.0.1:$port";
        }
        usleep(50000);
    }
    fwrite(STDERR, "FAIL: the local HTTP server did not start\n");
    exit(1);
}

/** Run the CEF loop until $condition() is true; fails the test after $timeoutMs. */
function wait_until(callable $condition, string $what, int $timeoutMs = 20000): bool
{
    $deadline = microtime(true) + $timeoutMs / 1000;
    while (microtime(true) < $deadline) {
        Cef::doMessageLoopWork();
        if ($condition()) {
            return true;
        }
        Cef::wait(5);
    }
    check(false, "timeout waiting for: $what");
    return false;
}

function finish(): never
{
    if ($GLOBALS['cl_failures'] > 0) {
        fwrite(STDERR, $GLOBALS['cl_failures'] . ' of ' . $GLOBALS['cl_checks'] . " checks failed\n");
        exit(1);
    }
    echo "OK ({$GLOBALS['cl_checks']} checks)\n";
    exit(0);
}
