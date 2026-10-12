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
