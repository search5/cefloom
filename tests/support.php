<?php
/**
 * Helpers of the cefloom tests.
 *
 * A test is a PHP script that requires this file, makes checks with check()/same()/throws(), and ends with finish(),
 * which prints "OK (N checks)" and exits with 0 (tests/run.sh passes a test whose output has such a line and no line starting
 * with "FAIL"). The failure and check counters live in $GLOBALS['cl_failures'] and
 * $GLOBALS['cl_checks'].
 */
declare(strict_types=1);

use Cefloom\Cef;

$GLOBALS['cl_failures'] = 0;
$GLOBALS['cl_checks'] = 0;

/** Count a check and report "FAIL: $what" on stderr when $condition is false; the test goes on. */
function check(bool $condition, string $what): void
{
    $GLOBALS['cl_checks']++;
    if (!$condition) {
        $GLOBALS['cl_failures']++;
        fwrite(STDERR, "FAIL: $what\n");
    }
}

/** Check that $expected === $actual; the failure message shows both values. */
function same(mixed $expected, mixed $actual, string $what): void
{
    check($expected === $actual, $what . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

/** Check that $fn() throws an instance of $class; a different or no exception fails. */
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

/**
 * Add the switches ozone-platform=x11 and disable-gpu, then call Cef::initialize().
 * Sets externalMessagePump to 1 if $CEFLOOM_TEST_EXTERNAL_PUMP is set. Exits with status 1 when Cef::initialize() returns false.
 *
 * @param Cefloom\Settings|null $settings Settings to use; an empty rootCachePath is set to <$CEFLOOM_TEST_TMP or build/tmp>/cache-<pid>, removed at exit.
 * @param Cefloom\App|null $app Application object passed to Cef::initialize().
 */
function start_cef(?Cefloom\Settings $settings = null, ?Cefloom\App $app = null): void
{
    Cef::addSwitch('ozone-platform', 'x11');
    Cef::addSwitch('disable-gpu');
    // Every test process gets its own cache directory: Chromium locks it.
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

/** Run the CEF message loop for $seconds; for waiting on something that has no condition to poll. */
function pump(float $seconds): void
{
    $end = microtime(true) + $seconds;
    while (microtime(true) < $end) {
        Cef::doMessageLoopWork();
        Cef::wait(5);
    }
}

/**
 * Send input to the display with xdotool, e.g. xdo('mousemove', '10', '20').
 * A failing xdotool is reported on stderr and does not fail the test.
 */
function xdo(string ...$args): void
{
    exec('xdotool ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, 'xdotool failed: ' . implode(' ', $out) . "\n");
    }
}

/** Return true when xdotool is installed and DISPLAY is set. */
function can_make_input(): bool
{
    exec('command -v xdotool', $found, $status);
    return $status === 0 && (bool) getenv('DISPLAY');
}

/** Create a new directory under the system temp directory; it is removed recursively when the test ends. */
function tmpdir(string $prefix = 'cefloom-test'): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($dir, 0777, true);
    register_shutdown_function(static function () use ($dir): void { exec('rm -rf ' . escapeshellarg($dir)); });
    return $dir;
}

/**
 * Run a PHP script in a separate process with the extension loaded, via `env ... timeout -k 5 $timeout php -d extension=$module`.
 *
 * @param string $script Path of the script.
 * @param string[] $args Command line arguments of the script.
 * @param array<string, string|null> $env Environment variables to set; a null value unsets the variable.
 * @param int $timeout Seconds before `timeout` sends TERM (KILL follows 5 seconds later).
 * @param string|null $module The cefloom.so to load; defaults to ($CEFLOOM_DIST or <repo>/build/dist)/cefloom.so.
 * @return array{0: int, 1: string} Exit code (124 if `timeout` stopped the process) and the stdout+stderr lines joined by "\n", except lines that match /^\[\d+:\d+:\d+\/|ERROR:|WARNING:|INFO:/.
 */
function run_php(string $script, array $args = [], array $env = [], int $timeout = 90, ?string $module = null): array
{
    $module ??= (getenv('CEFLOOM_DIST') ?: dirname(__DIR__) . '/build/dist') . '/cefloom.so';
    $envText = '';
    foreach ($env as $name => $value) {      // env wants its options (-u) before the assignments
        if ($value === null) {
            $envText .= ' -u ' . escapeshellarg($name);
        }
    }
    foreach ($env as $name => $value) {
        if ($value !== null) {
            $envText .= ' ' . $name . '=' . escapeshellarg((string) $value);
        }
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
 * The server is stopped when the test ends; exits with status 1 if it does not answer within about 5 seconds.
 *
 * @return string The base URL, "http://127.0.0.1:PORT".
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

/**
 * Run the CEF message loop until $condition() returns true.
 *
 * @param string $what Description used in the failure message.
 * @param int $timeoutMs Time limit; on expiry the check fails.
 * @return bool True if the condition became true, false on timeout.
 */
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

/** Print the result and exit: status 1 with a summary on stderr if any check failed, else "OK (N checks)" and status 0. */
function finish(): never
{
    if ($GLOBALS['cl_failures'] > 0) {
        fwrite(STDERR, $GLOBALS['cl_failures'] . ' of ' . $GLOBALS['cl_checks'] . " checks failed\n");
        exit(1);
    }
    echo "OK ({$GLOBALS['cl_checks']} checks)\n";
    exit(0);
}

/**
 * A URLRequestClient that records what arrives: the body, the progress, the status and the response headers.
 *
 * With $credentials = [user, password] it answers requests for credentials, at most $maxAsks times; with null it declines.
 */
final class Fetcher extends Cefloom\URLRequestClient
{
    public string $body = '';
    public bool $done = false;
    public array $down = [];
    public array $up = [];
    public array $asked = [];
    public ?int $status = null;
    public ?int $error = null;
    public ?int $httpStatus = null;
    public array $headers = [];
    public bool $cached = false;
    public int $maxAsks = 2;
    /** @param array{0: string, 1: string}|null $credentials User and password for getAuthCredentials(), or null to decline. */
    public function __construct(public ?array $credentials = null) {}
    /** Record the final status, error, HTTP status, headers and cache flag, and mark the fetch done. */
    public function onRequestComplete(Cefloom\URLRequest $request): void
    {
        $this->done = true;
        $this->status = $request->getRequestStatus();
        $this->error = $request->getRequestError();
        $response = $request->getResponse();
        if ($response !== null) {
            $this->httpStatus = $response->getStatus();
            $this->headers = $response->getHeaderMap();
        }
        $this->cached = $request->responseWasCached();
    }
    /** Append [$current, $total] to $up. */
    public function onUploadProgress(Cefloom\URLRequest $request, int $current, int $total): void { $this->up[] = [$current, $total]; }
    /** Append [$current, $total] to $down. */
    public function onDownloadProgress(Cefloom\URLRequest $request, int $current, int $total): void { $this->down[] = [$current, $total]; }
    /** Append the received bytes to $body. */
    public function onDownloadData(Cefloom\URLRequest $request, Cefloom\Buffer $data): void { $this->body .= $data->read(0, $data->getSize()); }
    /** Record the request; answer with $credentials and return true, or return false when there are none or it has already been asked $maxAsks times. */
    public function getAuthCredentials(bool $isProxy, string $host, int $port, string $realm, string $scheme, Cefloom\AuthCallback $callback): bool
    {
        $this->asked[] = [$isProxy, $host, $realm, $scheme];
        if ($this->credentials === null || count($this->asked) > $this->maxAsks) {
            return false;       // after $maxAsks tries the client gives up: a server that keeps saying 401 would ask for ever
        }
        $callback->continue($this->credentials[0], $this->credentials[1]);
        return true;
    }
}

/**
 * Start a request without waiting for it; wait with wait_until(fn() => $fetcher->done, ...).
 *
 * @param array<string, string> $headers Request headers.
 * @param string|null $body POST body; null sends none.
 * @param array{0: string, 1: string}|null $credentials Passed to the Fetcher.
 * @param int $flags Request flags (UR_FLAG_*).
 * @return array{0: Cefloom\URLRequest, 1: Fetcher} The request and its client.
 */
function start_fetch(string $url, string $method = 'GET', array $headers = [], ?string $body = null, ?array $credentials = null, int $flags = 0, ?Cefloom\RequestContext $context = null): array
{
    $request = Cefloom\Request::create();
    $request->setURL($url);
    $request->setMethod($method);
    if ($headers) {
        $request->setHeaderMap($headers);
    }
    if ($body !== null) {
        $post = Cefloom\PostData::create();
        $element = Cefloom\PostDataElement::create();
        $element->setToBytes($body);
        $post->addElement($element);
        $request->setPostData($post);
    }
    $request->setFlags($flags);
    $fetcher = new Fetcher($credentials);
    return [Cefloom\URLRequest::create($request, $fetcher, $context), $fetcher];
}

/**
 * Fetch a URL and wait up to 20 seconds for it; parameters as for start_fetch().
 *
 * @return Fetcher The client holding the result; its $done is false on timeout.
 */
function fetch_url(string $url, string $method = 'GET', array $headers = [], ?string $body = null, ?array $credentials = null, int $flags = 0, ?Cefloom\RequestContext $context = null): Fetcher
{
    [$request, $fetcher] = start_fetch($url, $method, $headers, $body, $credentials, $flags, $context);
    wait_until(fn() => $fetcher->done, "the request for $url", 20000);
    return $fetcher;
}

/**
 * Make a self-signed certificate for 127.0.0.1 and localhost in a new directory and start fixtures/https_server.php on a free port with it.
 * The server stops when the test ends; exits with status 1 if openssl fails or the server does not report ready.
 *
 * @param string $commonName Subject CN of the certificate.
 * @param bool $requireClientCertificate Make the server ask the client for a certificate.
 * @return array{url: string, dir: string, cert: string, key: string, port: int} Base URL "https://127.0.0.1:PORT", the directory and the PEM paths.
 */
function start_https_server(string $commonName = 'cefloom-test', bool $requireClientCertificate = false): array
{
    $dir = tmpdir('cefloom-tls');
    $cert = "$dir/server.pem";
    $key = "$dir/server.key";
    exec('openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg($key) . ' -out ' . escapeshellarg($cert) . ' -days 2 -subj '
        . escapeshellarg("/CN=$commonName/O=Cefloom Tests/C=KR") . ' -addext ' . escapeshellarg('subjectAltName=IP:127.0.0.1,DNS:localhost') . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "FAIL: openssl could not make a certificate: " . implode(' ', $out) . "\n");
        exit(1);
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $command = [PHP_BINARY, __DIR__ . '/fixtures/https_server.php', (string) $port, $cert, $key];
    if ($requireClientCertificate) {
        $command[] = 'require-client-cert';
    }
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $ready = fgets($pipes[1]);
    if (trim((string) $ready) !== 'ready') {
        fwrite(STDERR, 'FAIL: the HTTPS server did not start: ' . stream_get_contents($pipes[2]) . "\n");
        exit(1);
    }
    register_shutdown_function(static function () use ($process): void {
        $status = proc_get_status($process);
        if ($status['running']) {
            posix_kill($status['pid'], SIGKILL);
        }
        proc_close($process);
    });
    return ['url' => "https://127.0.0.1:$port", 'dir' => $dir, 'cert' => $cert, 'key' => $key, 'port' => $port];
}
