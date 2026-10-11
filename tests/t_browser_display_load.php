<?php
// DisplayHandler (title, address, favicon, status, progress, console) and LoadHandler (start/end/error, loading state).
require __DIR__ . '/t_browser_common.php';

use Cefloom\{DisplayHandler, LoadHandler};
use Cefloom\Types\{ErrorCode, TransitionType};

final class Disp extends DisplayHandler
{
    public array $titles = [], $addresses = [], $favicons = [], $status = [], $progress = [], $console = [];
    public function onTitleChange($browser, $title): void { $this->titles[] = $title; }
    public function onAddressChange($browser, $frame, $url): void { $this->addresses[] = [$url, $frame->isMain()]; }
    public function onFaviconURLChange($browser, $iconUrls): void { $this->favicons[] = $iconUrls; }
    public function onStatusMessage($browser, $value): void { $this->status[] = $value; }
    public function onLoadingProgressChange($browser, $progress): void { $this->progress[] = $progress; }
    public function onConsoleMessage($browser, $level, $message, $source, $line): bool
    {
        $this->console[] = [$level, $message, $source, $line];
        return true;
    }
}

final class State extends Load
{
    public array $changes = [], $starts = [], $errors = [];
    public function onLoadingStateChange($browser, $isLoading, $canGoBack, $canGoForward): void { $this->changes[] = [$isLoading, $canGoBack, $canGoForward]; }
    public function onLoadStart($browser, $frame, $transitionType): void { parent::onLoadStart($browser, $frame, $transitionType); $this->starts[] = [$frame->getURL(), $frame->isMain(), $transitionType]; }
    public function onLoadError($browser, $frame, $errorCode, $errorText, $failedUrl): void { parent::onLoadError($browser, $frame, $errorCode, $errorText, $failedUrl); $this->errors[] = [$errorCode, $errorText, $failedUrl, $frame->isMain()]; }
}

page('/index.html', html('Start Title', '<a id=l href="#frag">x</a>',
    'console.log("hello", 1); console.warn("careful"); console.error("bad");'),
    'text/html');
page('/fav.html', '<!doctype html><html><head><title>Fav</title><link rel="icon" href="/icon.png"></head><body>fav</body></html>');
page('/icon.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='), 'image/png');
page('/status.html', html('Status', '<a id=l href="/two.html">hover</a>'));
page('/missing.html', 'nope', 'text/plain', 404);

start_cef();
$c = new TestClient();
$c->display = $d = new Disp();
$c->load = $st = new State();
$b = open_browser($c);
wait_until(fn() => in_array('Start Title', $d->titles, true), 'title change');
same('Start Title', end($d->titles), 'onTitleChange');
check(in_array([BASE . '/index.html', true], $d->addresses, true), 'onAddressChange main frame: ' . json_encode($d->addresses));
wait_until(fn() => count($d->console) >= 3, 'console messages');
same('hello 1', $d->console[0][1] ?? null, 'console.log message');
same('careful', $d->console[1][1] ?? null, 'console.warn message');
check(($d->console[0][0] ?? null) !== ($d->console[1][0] ?? null), 'levels differ log/warn');
check(($d->console[2][0] ?? 0) > ($d->console[1][0] ?? 0), 'error level above warning level');
same(BASE . '/index.html', $d->console[0][2] ?? null, 'console source is the page url');
same(1, $d->console[0][3] ?? null, 'console line');
check(count($d->progress) > 0, 'loading progress reported');
check(end($d->progress) === 1.0, 'final progress is 1.0 (float): ' . var_export(end($d->progress), true));
foreach ($d->progress as $p) { if (!is_float($p) || $p < 0 || $p > 1) { check(false, 'progress in 0..1'); } }

wait_until(fn() => count($st->changes) >= 2, 'loading state changes');
same(true, $st->changes[0][0], 'first state: loading');
same(false, $st->changes[array_key_last($st->changes)][0], 'last state: not loading');
check(count($st->starts) >= 1 && $st->starts[0][1] === true, 'onLoadStart main frame');
same(BASE . '/index.html', $st->starts[0][0], 'onLoadStart url');
check(is_int($st->starts[0][2]), 'transition type is int');

// favicon
$b->getMainFrame()->loadURL(BASE . '/fav.html');
wait_until(fn() => in_array([BASE . '/icon.png'], $d->favicons, true), 'favicon urls');
check(in_array([BASE . '/icon.png'], $d->favicons, true), 'onFaviconURLChange list: ' . json_encode($d->favicons));

// fragment navigation updates the address
wait_until(fn() => isset($st->ends[BASE . '/fav.html']), 'fav loaded');
$d->addresses = [];
js($c, 'location.hash = "#frag2"');
wait_until(fn() => $d->addresses !== [], 'address change on fragment');
same(BASE . '/fav.html#frag2', $d->addresses[0][0] ?? null, 'address with fragment');

// title change by script
js($c, 'document.title = "changed"');
wait_until(fn() => end($d->titles) === 'changed', 'script title');
same('changed', end($d->titles), 'title changed by script');

// load error: a url that cannot connect and an aborted one
$st->errors = [];
$b->getMainFrame()->loadURL('http://127.0.0.1:59999/unreachable');
wait_until(fn() => $st->errors !== [], 'load error', 30000);
$e = $st->errors[0] ?? [];
same(ErrorCode::CONNECTION_REFUSED, $e[0] ?? null, 'error code CONNECTION_REFUSED');
check(is_string($e[1] ?? null), 'error text is string');
same('http://127.0.0.1:59999/unreachable', $e[2] ?? null, 'failed url');
same(true, $e[3] ?? null, 'error frame is main');
wait_until(fn() => end($st->changes)[0] === false, 'not loading after error');

// HTTP 404 is a normal load end with a status
$b->getMainFrame()->loadURL(BASE . '/missing.html');
wait_until(fn() => isset($st->ends[BASE . '/missing.html']), '404 loaded');
same(404, $st->ends[BASE . '/missing.html'] ?? null, 'onLoadEnd status 404');

unset($b);
shutdown_cef($c);
finish();
