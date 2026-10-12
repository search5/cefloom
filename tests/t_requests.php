<?php
// The callbacks of a request on its way (docs/handlers.md, "Callbacks on other threads"), against the local HTTP server:
//   asynchronous: onBeforeResourceLoad (cancel, change the request, continue later), onResourceLoadComplete, getAuthCredentials
//   synchronous:  getResourceRequestHandler, onResourceRedirect, onResourceResponse, getResourceResponseFilter, onProtocolExecution
// The page that makes the requests comes from the same server, so that its fetch() can read the answers.
require __DIR__ . '/support.php';

use Cefloom\{AuthCallback, Browser, BrowserHost, BrowserSettings, Buffer, Callback, Cef, Client, DisplayHandler, Frame, LifeSpanHandler,
    Request, RequestHandler, ResourceRequestHandler, Response, ResponseFilter, WindowInfo};
use Cefloom\Types\{ResponseFilterStatus, ReturnValue, URLRequestStatus};

$base = start_http_server();
$GLOBALS['events'] = [];     // what the handlers saw
$GLOBALS['results'] = [];    // what the page logged
$GLOBALS['hold'] = [];       // callbacks kept for later
$GLOBALS['auth'] = 'continue';   // what getAuthCredentials does: continue | cancel | decline

final class Resource extends ResourceRequestHandler
{
    public function __construct(private string $base) {}

    public function onBeforeResourceLoad($browser, $frame, $request, $callback): int
    {
        $path = parse_url($request->getURL(), PHP_URL_PATH);
        $GLOBALS['events'][] = "before:$path";
        switch ($path) {
            case '/cancel-me':
                return ReturnValue::CANCEL;
            case '/rewrite-me':
                $request->setURL($this->base . '/hello');       // a changed URL is a redirect
                return ReturnValue::CONTINUE;
            case '/decide-later':
                $GLOBALS['hold']['decide-later'] = $callback;
                return ReturnValue::CONTINUE_ASYNC;
            case '/cancel-later':
                $GLOBALS['hold']['cancel-later'] = $callback;
                return ReturnValue::CONTINUE_ASYNC;
            case '/slow-decision':
                return ReturnValue::CONTINUE_ASYNC;             // never answers: the callback is dropped
        }
        return ReturnValue::CONTINUE;
    }

    public function onResourceRedirect($browser, $frame, $request, $response, $newUrl): string
    {
        $GLOBALS['events'][] = 'redirect:' . parse_url($request->getURL(), PHP_URL_PATH) . '->' . parse_url($newUrl, PHP_URL_PATH) . ':' . $response->getStatus();
        if (str_contains($newUrl, '/to-be-changed')) {
            return $this->base . '/hello?changed=1';
        }
        return $newUrl;
    }

    public function onResourceResponse($browser, $frame, $request, $response): bool
    {
        $GLOBALS['events'][] = 'response:' . parse_url($request->getURL(), PHP_URL_PATH) . ':' . $response->getStatus();
        return false;
    }

    public function getResourceResponseFilter($browser, $frame, $request, $response): ?ResponseFilter
    {
        if (str_contains($request->getURL(), 'upper=1')) {
            return new Upper();
        }
        return null;
    }

    public function onResourceLoadComplete($browser, $frame, $request, $response, int $status, int $receivedContentLength): void
    {
        $GLOBALS['events'][] = 'complete:' . parse_url($request->getURL(), PHP_URL_PATH) . ':' . $response->getStatus() . ':' . $status . ':' . $receivedContentLength;
    }

    public function onProtocolExecution($browser, $frame, $request): bool
    {
        $GLOBALS['events'][] = 'protocol:' . $request->getURL();
        return false;
    }
}

/** Makes the body upper case: reads what CEF has, writes what fits. */
final class Upper extends ResponseFilter
{
    public function initFilter(): bool { $GLOBALS['events'][] = 'filter:init'; return true; }
    public function filter(?Buffer $dataIn, Buffer $dataOut): array
    {
        // [status, bytes read, bytes written]; null data is the end of the stream
        if ($dataIn === null) {
            $GLOBALS['events'][] = 'filter:end';
            return [ResponseFilterStatus::DONE, 0, 0];
        }
        $text = $dataIn->read(0, min($dataIn->getSize(), $dataOut->getSize()));
        $dataOut->write(strtoupper($text));
        return [ResponseFilterStatus::NEED_MORE_DATA, strlen($text), strlen($text)];
    }
}

final class Requests extends RequestHandler
{
    private Resource $resource;
    public function __construct(string $base) { $this->resource = new Resource($base); }

    public function getResourceRequestHandler($browser, $frame, $request, $isNavigation, $isDownload, $requestInitiator): array
    {
        return [$this->resource, false];
    }

    public function getAuthCredentials($browser, $originUrl, $isProxy, $host, $port, $realm, $scheme, $callback): bool
    {
        $GLOBALS['events'][] = "auth:$host:$realm:$scheme:" . var_export($isProxy, true);
        if ($GLOBALS['auth'] === 'decline') {
            return false;
        }
        if ($GLOBALS['auth'] === 'cancel') {
            $callback->cancel();
        } else {
            $callback->continue('u', 'p');
        }
        return true;
    }
}

final class Console extends DisplayHandler
{
    public function onConsoleMessage($browser, $level, $message, $source, $line): bool
    {
        if (preg_match('/^R:(\w+):(.*)$/s', $message, $m)) {
            $GLOBALS['results'][$m[1]] = json_decode($m[2], true);
        }
        return false;
    }
}
final class Life extends LifeSpanHandler
{
    public function __construct(private App $app) {}
    public function onAfterCreated($browser): void { $this->app->browser = $browser; }
}
final class App extends Client
{
    public $browser = null;
    private Console $console;
    private Life $life;
    private Requests $requests;
    public function __construct(string $base) { $this->console = new Console(); $this->life = new Life($this); $this->requests = new Requests($base); }
    public function getDisplayHandler(): ?DisplayHandler { return $this->console; }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
    public function getRequestHandler(): ?RequestHandler { return $this->requests; }
}

start_cef();
$app = new App($base);
$info = new WindowInfo();
$info->bounds->width = 300;
$info->bounds->height = 200;
$script = '<script>
async function f(name, url, opts, delay) {
  const log = (o) => setTimeout(() => console.log("R:" + name + ":" + JSON.stringify(o)), delay || 0);
  try { const r = await fetch(url, opts); const t = await r.text(); log({status: r.status, url: r.url.replace(location.origin, ""), text: t.slice(0, 60)}); }
  catch (e) { log({error: String(e.name)}); }
}
console.log("R:ready:{}");
</script>';
BrowserHost::createBrowser($info, $app, "$base/page?title=requests&body=" . rawurlencode($script), new BrowserSettings(), null, null);

$pump_until = function (callable $done, int $timeoutMs = 20000): bool {
    $end = microtime(true) + $timeoutMs / 1000;
    while (microtime(true) < $end) {
        Cef::doMessageLoopWork();
        if ($done()) {
            return true;
        }
        Cef::wait(5);
    }
    return false;
};
$pump_until(fn() => isset($GLOBALS['results']['ready']) && $app->browser !== null);
check(isset($GLOBALS['results']['ready']), 'the page from the local server is loaded');

$run = function (string $name, string $path, string $opts = '{}', int $timeoutMs = 20000) use ($app, $pump_until): ?array {
    unset($GLOBALS['results'][$name]);
    $app->browser->getMainFrame()->executeJavaScript("f(" . json_encode($name) . ", " . json_encode($path) . ", $opts)", '', 0);
    $pump_until(fn() => isset($GLOBALS['results'][$name]), $timeoutMs);
    return $GLOBALS['results'][$name] ?? null;
};
$ev = fn(string $prefix) => array_values(array_filter($GLOBALS['events'], fn($e) => str_starts_with($e, $prefix)));

// ---- onResourceLoadComplete and onResourceResponse: every request is reported with its status and its size
$r = $run('hello', '/hello');
same([200, 'hello'], [$r['status'] ?? null, $r['text'] ?? null], 'a request through the handlers is answered');
check(in_array('response:/hello:200', $GLOBALS['events'], true), 'onResourceResponse saw the response');
$pump_until(fn() => $ev('complete:/hello') !== [], 5000);
same(['complete:/hello:200:' . URLRequestStatus::SUCCESS . ':5'], $ev('complete:/hello'), 'onResourceLoadComplete reports the status, the load status (SUCCESS) and the 5 bytes');

// ---- onBeforeResourceLoad
$r = $run('cancel_me', '/cancel-me');
same('TypeError', $r['error'] ?? null, 'RV_CANCEL cancels the request');
check($ev('response:/cancel-me') === [], 'which never reached the server');

$r = $run('rewrite_me', '/rewrite-me');
same(['/hello', 'hello'], [$r['url'] ?? null, $r['text'] ?? null], 'a request whose URL was changed goes to the new URL');

unset($GLOBALS['results']['decide_later']);
$app->browser->getMainFrame()->executeJavaScript('f("decide_later", "/decide-later")', '', 0);
$pump_until(fn() => isset($GLOBALS['hold']['decide-later']), 10000);
pump(0.4);
check(!isset($GLOBALS['results']['decide_later']), 'with CONTINUE_ASYNC the request waits for the callback');
$GLOBALS['hold']['decide-later']->continue();
$pump_until(fn() => isset($GLOBALS['results']['decide_later']), 10000);
same(404, $GLOBALS['results']['decide_later']['status'] ?? null, 'after callback->continue() it goes on (the server has no such page: 404)');

unset($GLOBALS['results']['cancel_later']);
$app->browser->getMainFrame()->executeJavaScript('f("cancel_later", "/cancel-later")', '', 0);
$pump_until(fn() => isset($GLOBALS['hold']['cancel-later']), 10000);
$GLOBALS['hold']['cancel-later']->cancel();
$pump_until(fn() => isset($GLOBALS['results']['cancel_later']), 10000);
same('TypeError', $GLOBALS['results']['cancel_later']['error'] ?? null, 'after callback->cancel() it fails');

// ---- redirects: onResourceRedirect sees them and may change the target
$r = $run('redirect', '/redirect?to=/hello&code=302');
same(['/hello', 'hello'], [$r['url'] ?? null, $r['text'] ?? null], 'a redirect of the server is followed');
check(in_array('redirect:/redirect->/hello:302', $GLOBALS['events'], true), 'onResourceRedirect saw it, with the status of the response');
// (a URL that onResourceRedirect returns is not followed by CEF in this build: t_redirect_change records it as a known problem)
$r = $run('redirect_same', '/redirect?to=/hello&code=301');
same(['/hello', 'hello'], [$r['url'] ?? null, $r['text'] ?? null], 'a handler that returns the URL it was given leaves the redirect as it is');

// ---- getResourceResponseFilter: the body goes through the filter
$GLOBALS['events'] = [];
$r = $run('upper', '/hello?upper=1');
same([200, 'HELLO'], [$r['status'] ?? null, $r['text'] ?? null], 'the body of a response with a filter is what the filter made of it');
same(['filter:init', 'filter:end'], $ev('filter:'), 'the filter is initialised, and is told when the stream ends (null data)');
$r = $run('plain', '/hello');
same('hello', $r['text'] ?? null, 'a response without a filter is untouched');

// ---- getAuthCredentials
$GLOBALS['events'] = [];
$GLOBALS['auth'] = 'continue';
$r = $run('auth_ok', '/a1/auth?realm=ok');
same([200, 'authorized as u'], [$r['status'] ?? null, $r['text'] ?? null], 'a 401 with a challenge asks getAuthCredentials, and the credentials given with callback->continue() are used');
check(count($ev('auth:')) >= 1 && str_contains($ev('auth:')[0], ':ok:') && str_contains($ev('auth:')[0], 'basic'), 'it is told the realm and the scheme (' . ($ev('auth:')[0] ?? 'nothing') . ')');
$GLOBALS['auth'] = 'cancel';
$r = $run('auth_cancel', '/a2/auth?realm=cancel');
same(401, $r['status'] ?? null, 'callback->cancel() leaves the 401 as it is');
$GLOBALS['auth'] = 'decline';
$r = $run('auth_decline', '/a3/auth?realm=decline');
same(401, $r['status'] ?? null, 'a handler that returns false declines: the 401');

// ---- onProtocolExecution: a link to a protocol nobody handles
$GLOBALS['events'] = [];
$app->browser->getMainFrame()->executeJavaScript('location.href = "cefloom-test-protocol:hello"', '', 0);
$pump_until(fn() => $ev('protocol:') !== [], 5000);
same(['protocol:cefloom-test-protocol:hello'], $ev('protocol:'), 'onProtocolExecution is told about a link to a protocol that nobody handles');

unset($app->browser);
Cef::shutdown();
finish();
