<?php
// Resources served by PHP through a custom scheme (ext/src/async.cc). The handlers answer CEF at once and PHP runs later on the
// PHP thread; what CEF needs from the handler (headers, body, skip, cancel, an answer that comes later, a failure) has to
// arrive. A page makes the requests with fetch(); each scenario logs one line that the test reads.
require __DIR__ . '/support.php';

use Cefloom\{Browser, BrowserHost, BrowserSettings, Buffer, Callback, Cef, Client, DisplayHandler, Frame, LoadHandler, Request,
    ResourceHandler, ResourceReadCallback, ResourceSkipCallback, Response, SchemeHandlerFactory, WindowInfo};
use Cefloom\Types\SchemeOptions;

$GLOBALS['log'] = [];        // what the handlers saw, in order
$GLOBALS['results'] = [];    // name => what the page logged
$GLOBALS['pending'] = [];    // callbacks the handlers keep for later

/** One resource: what the handler does, by the path. */
function spec(string $path): ?array
{
    $item = [];
    if (preg_match('#^/item/(\d+)$#', $path, $m)) {
        return ['body' => 'item ' . $m[1]];
    }
    return match ($path) {
        '/index.html' => ['body' => '<html><body><script>
            // delay: when the handler throws, the exception comes out of the loop in the turn in which the request fails, and
            // the callbacks of that turn do not run PHP: the result is logged a little later, when the loop is clear again
            async function f(name, url, opts, delay) {
              const log = (o) => setTimeout(() => console.log("R:" + name + ":" + JSON.stringify(o)), delay || 0);
              try { const r = await fetch(url, opts); const t = await r.text();
                    log({status: r.status, url: r.url, len: t.length, head: t.slice(0, 40), type: r.headers.get("content-type"), custom: r.headers.get("x-custom")}); }
              catch (e) { log({error: String(e.name)}); }
            }
            async function many() {
              const texts = await Promise.all(Array.from({length: 50}, (_, i) => fetch("app://test/item/" + i).then(r => r.text())));
              console.log("R:many:" + JSON.stringify({n: texts.length, ok: texts.every((t, i) => t === "item " + i)}));
            }
            async function aborted() {
              const c = new AbortController();
              setTimeout(() => c.abort(), 300);
              try { await fetch("app://test/hang", {signal: c.signal}); console.log("R:aborted:" + JSON.stringify({error: "none"})); }
              catch (e) { console.log("R:aborted:" + JSON.stringify({error: String(e.name)})); }
            }
            console.log("R:ready:{}");
            </script></body></html>', 'mime' => 'text/html'],
        '/text' => ['body' => 'plain text', 'headers' => ['X-Custom' => 'yes']],
        '/big' => ['body' => str_repeat('0123456789abcdef', 3 * 65536), 'chunk' => 0],             // 3 MB
        '/small-chunks' => ['body' => str_repeat('x', 100000), 'chunk' => 1000],                  // read() gives 1000 bytes a time
        '/missing' => ['body' => 'no such page', 'status' => 404],
        '/error500' => ['body' => 'broken', 'status' => 500],
        '/redirect-header' => ['body' => '', 'status' => 302, 'headers' => ['Location' => 'app://test/text']],
        '/redirect-url' => ['body' => '', 'redirect' => 'app://test/text'],
        '/refuse' => ['open' => [false, true]],                                                    // open() says: not mine
        '/throw-open' => ['throw' => 'open'],
        '/throw-headers' => ['body' => 'x', 'throw' => 'headers'],
        '/throw-read' => ['body' => 'x', 'throw' => 'read'],
        '/later' => ['body' => 'answered later', 'open' => [true, false], 'later' => true],       // open() continues through the callback
        '/later-cancel' => ['body' => 'never', 'open' => [true, false], 'later' => 'cancel'],      // ... and cancels it
        '/hang' => ['body' => 'never', 'open' => [true, false], 'later' => 'hold'],               // keeps the callback and never uses it: only an abort ends it
        '/legacy' => ['body' => 'legacy handler', 'legacy' => true],                              // processRequest() and readResponse()
        '/range' => ['body' => str_repeat('abcdefghij', 1000), 'range' => true],
        '/null' => null,
        default => null,
    };
}

final class Res extends ResourceHandler
{
    private int $position = 0;
    private int $reads = 0;
    public function __construct(private string $path, private array $spec) {}

    public function open(Request $request, Callback $callback): array
    {
        $GLOBALS['log'][] = "open:{$this->path}";
        if (($this->spec['throw'] ?? '') === 'open') {
            throw new RuntimeException('open failed');
        }
        $result = $this->spec['open'] ?? [true, true];
        if (($this->spec['later'] ?? false) !== false) {
            $GLOBALS['pending'][$this->path] = [$callback, $this->spec['later']];
        }
        return $result;
    }

    public function getResponseHeaders(Response $response): array
    {
        $GLOBALS['log'][] = "headers:{$this->path}";
        if (($this->spec['throw'] ?? '') === 'headers') {
            throw new RuntimeException('headers failed');
        }
        $response->setStatus($this->spec['status'] ?? 200);
        $response->setMimeType($this->spec['mime'] ?? 'text/plain');
        foreach ($this->spec['headers'] ?? [] as $name => $value) {
            $response->setHeaderByName($name, $value, true);
        }
        return [strlen($this->spec['body'] ?? ''), $this->spec['redirect'] ?? ''];
    }

    public function read(Buffer $dataOut, ResourceReadCallback $callback): array
    {
        $this->reads++;
        $GLOBALS['log'][] = "read:{$this->path}";
        if (($this->spec['throw'] ?? '') === 'read') {
            throw new RuntimeException('read failed');
        }
        $body = $this->spec['body'] ?? '';
        if ($this->position >= strlen($body)) {
            return [false, 0];
        }
        $size = $dataOut->getSize();
        $chunk = ($this->spec['chunk'] ?? 0) > 0 ? min($size, $this->spec['chunk']) : $size;
        $written = $dataOut->write(substr($body, $this->position, $chunk));
        $this->position += $written;
        return [true, $written];
    }

    public function skip(int $bytesToSkip, ResourceSkipCallback $callback): array
    {
        $GLOBALS['log'][] = "skip:{$this->path}:$bytesToSkip";
        $this->position += $bytesToSkip;
        return [true, $bytesToSkip];
    }

    public function cancel(): void
    {
        $GLOBALS['log'][] = "cancel:{$this->path}";
    }

    public function reads(): int { return $this->reads; }
}

/** The old way: processRequest() answers whether the request is taken, readResponse() gives the body. */
final class Legacy extends ResourceHandler
{
    private int $position = 0;
    public function __construct(private string $body) {}
    public function processRequest(Request $request, Callback $callback): bool
    {
        $GLOBALS['log'][] = 'processRequest:/legacy';
        $callback->continue();
        return true;
    }
    public function getResponseHeaders(Response $response): array
    {
        $response->setStatus(200);
        $response->setMimeType('text/plain');
        return [strlen($this->body), ''];
    }
    public function readResponse(Buffer $dataOut, Callback $callback): array
    {
        $GLOBALS['log'][] = 'readResponse:/legacy';
        if ($this->position >= strlen($this->body)) {
            return [false, 0];
        }
        $written = $dataOut->write(substr($this->body, $this->position, $dataOut->getSize()));
        $this->position += $written;
        return [true, $written];
    }
    public function cancel(): void {}
}

final class Factory extends SchemeHandlerFactory
{
    public array $seen = [];
    public function create(?Browser $browser, ?Frame $frame, string $schemeName, Request $request): ?ResourceHandler
    {
        $path = parse_url($request->getURL(), PHP_URL_PATH);
        $this->seen[] = [$path, $browser !== null, $frame !== null, $schemeName];
        $GLOBALS['log'][] = "create:$path";
        if ($path === '/legacy') {
            return new Legacy(spec($path)['body']);
        }
        $spec = spec($path);
        return $spec === null ? null : new Res($path, $spec);
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
final class Life extends Cefloom\LifeSpanHandler
{
    public function __construct(private App $app) {}
    public function onAfterCreated($browser): void { $this->app->browser = $browser; }
}
final class App extends Client
{
    public Console $console;
    public Life $life;
    public $browser = null;
    public function __construct() { $this->console = new Console(); $this->life = new Life($this); }
    public function getDisplayHandler(): ?DisplayHandler { return $this->console; }
    public function getLifeSpanHandler(): ?Cefloom\LifeSpanHandler { return $this->life; }
}

Cef::registerCustomScheme('app', SchemeOptions::STANDARD | SchemeOptions::SECURE | SchemeOptions::CORS_ENABLED | SchemeOptions::FETCH_ENABLED);
start_cef();
$factory = new Factory();
check(Cef::registerSchemeHandlerFactory('app', 'test', $factory), 'registerSchemeHandlerFactory');
$app = new App();
$info = new WindowInfo();
$info->bounds->width = 300;
$info->bounds->height = 200;
BrowserHost::createBrowser($info, $app, 'app://test/index.html', new BrowserSettings(), null, null);

wait_until(fn() => isset($GLOBALS['results']['ready']) && $app->browser !== null, 'the page is loaded and its script is ready');
$GLOBALS['caught'] = [];

/** Pump until $done() or the time is up; an exception of a handler is noted and the loop goes on. */
$pump_until = function (callable $done, int $timeoutMs = 20000): bool {
    $end = microtime(true) + $timeoutMs / 1000;
    while (microtime(true) < $end) {
        try {
            Cef::doMessageLoopWork();
        } catch (Throwable $e) {
            $GLOBALS['caught'][] = $e->getMessage();
        }
        if ($done()) {
            return true;
        }
        Cef::wait(5);
    }
    return false;
};

/** Run JavaScript in the page and wait for the line it logs under $name (null if there is none). */
$run = function (string $js, string $name, int $timeoutMs = 20000) use ($app, $pump_until): ?array {
    unset($GLOBALS['results'][$name]);
    $app->browser->getMainFrame()->executeJavaScript($js, '', 0);
    $pump_until(fn() => isset($GLOBALS['results'][$name]), $timeoutMs);
    return $GLOBALS['results'][$name] ?? null;
};
$fetch = fn(string $name, string $path, string $opts = '{}', int $delay = 0) => $run("f(" . json_encode($name) . ", 'app://test$path', $opts, $delay)", $name);
$logged = fn(string $prefix) => array_values(array_filter($GLOBALS['log'], fn($l) => str_starts_with($l, $prefix)));
$position = fn(string $entry) => array_search($entry, $GLOBALS['log'], true);

// ---- the plain case: the page, the headers, the body, in order
$r = $fetch('text', '/text');
same(200, $r['status'] ?? null, 'a resource is served: status');
same(['len' => 10, 'head' => 'plain text', 'type' => 'text/plain', 'custom' => 'yes'], array_intersect_key($r ?? [], array_flip(['len', 'head', 'type', 'custom'])),
    'its body, its type and a header that PHP set arrive');
check($position('create:/text') < $position('open:/text') && $position('open:/text') < $position('headers:/text') && $position('headers:/text') < $position('read:/text'),
    'create, open, getResponseHeaders and read happen in that order');
$seen = array_values(array_filter($factory->seen, fn($x) => $x[0] === '/text'))[0] ?? null;
same(['/text', true, true, 'app'], $seen, 'the factory is given the browser, the frame and the scheme of a page request');

// ---- the body: more than one read, chunks of any size
$r = $fetch('big', '/big');
same(3145728, $r['len'] ?? null, 'a body of 3 MB arrives whole');
check(count($logged('read:/big')) > 1, 'it took several read() calls (' . count($logged('read:/big')) . ')');
$r = $fetch('chunks', '/small-chunks');
same(100000, $r['len'] ?? null, 'a body that read() gives in pieces of 1000 bytes arrives whole');
check(count($logged('read:/small-chunks')) >= 100, 'in at least 100 reads (' . count($logged('read:/small-chunks')) . ')');

// ---- status and redirects
$r = $fetch('missing', '/missing');
same([404, 'no such page'], [$r['status'] ?? null, $r['head'] ?? null], 'a 404 with a body');
$r = $fetch('error500', '/error500');
same(500, $r['status'] ?? null, 'a 500');
$r = $fetch('redirect_header', '/redirect-header');
same([200, 'app://test/text', 'plain text'], [$r['status'] ?? null, $r['url'] ?? null, $r['head'] ?? null], 'a redirect by status 302 and a Location header is followed');
$r = $fetch('redirect_url', '/redirect-url');
same([200, 'app://test/text', 'plain text'], [$r['status'] ?? null, $r['url'] ?? null, $r['head'] ?? null], 'a redirect by the redirectUrl that getResponseHeaders() returns is followed');

// ---- a request that PHP refuses or that fails
$r = $fetch('refuse', '/refuse');
same('TypeError', $r['error'] ?? null, 'open() that says "not mine" fails the request');
check($logged('headers:/refuse') === [], 'and getResponseHeaders() is not asked');
$r = $fetch('null', '/null');
same('TypeError', $r['error'] ?? null, 'a factory that returns null fails the request');

// ---- exceptions of the handler: the request fails, the exception comes out of the loop, the next request works
foreach (['open' => '/throw-open', 'headers' => '/throw-headers', 'read' => '/throw-read'] as $where => $path) {
    $GLOBALS['caught'] = [];
    $r = $fetch("throw_$where", $path, '{}', 500);
    check(isset($r['error']), "an exception in $where() fails the request (" . json_encode($r) . ')');
    check(in_array("$where failed", $GLOBALS['caught'], true), "and comes out of the loop (" . implode(',', $GLOBALS['caught']) . ')');
}
$r = $fetch('after_throw', '/text');
same(200, $r['status'] ?? null, 'a request after the exceptions is served');

// ---- an answer that comes later: open() keeps the callback
unset($GLOBALS['results']['later']);
$app->browser->getMainFrame()->executeJavaScript('f("later", "app://test/later")', '', 0);
$pump_until(fn() => isset($GLOBALS['pending']['/later']), 10000);
pump(0.4);
check(!isset($GLOBALS['results']['later']), 'while PHP has not continued, the request waits');
$GLOBALS['pending']['/later'][0]->continue();
$pump_until(fn() => isset($GLOBALS['results']['later']), 10000);
same([200, 'answered later'], [$GLOBALS['results']['later']['status'] ?? null, $GLOBALS['results']['later']['head'] ?? null], 'after callback->continue() the response arrives');

unset($GLOBALS['results']['later_cancel']);
$app->browser->getMainFrame()->executeJavaScript('f("later_cancel", "app://test/later-cancel")', '', 0);
$pump_until(fn() => isset($GLOBALS['pending']['/later-cancel']), 10000);
$GLOBALS['pending']['/later-cancel'][0]->cancel();
$pump_until(fn() => isset($GLOBALS['results']['later_cancel']), 10000);
same('TypeError', $GLOBALS['results']['later_cancel']['error'] ?? null, 'after callback->cancel() the request fails');

// ---- cancel(): the page gives up on a request that never continues
$r = $run('aborted()', 'aborted');
same('AbortError', $r['error'] ?? null, 'the page aborts a request');
$pump_until(fn() => $logged('cancel:/hang') !== [], 10000);
same(['cancel:/hang'], $logged('cancel:/hang'), 'and cancel() of the handler is called once');

// ---- the old way
$r = $fetch('legacy', '/legacy');
same([200, 'legacy handler'], [$r['status'] ?? null, $r['head'] ?? null], 'a handler with processRequest() and readResponse() is served');
check($logged('processRequest:/legacy') !== [] && $logged('readResponse:/legacy') !== [] && $logged('open:/legacy') === [], 'through those methods (' . implode(',', $logged('processRequest')) . ' ' . count($logged('readResponse')) . ' reads)');

// ---- a Range request
$r = $fetch('range', '/range', "{headers: {Range: 'bytes=100-199'}}");
same(['skip:/range:100'], $logged('skip:'), 'CEF asks the handler to skip the bytes before the range with skip()');
same([200, 9900, 'abcdefghij'], [$r['status'] ?? null, $r['len'] ?? null, substr($r['head'] ?? '', 0, 10)], 'and the response is what is left after them');

// ---- many requests at the same time
$GLOBALS['log'] = [];
$r = $run('many()', 'many');
same(['n' => 50, 'ok' => true], $r, 'fifty requests at once are each answered with their own body');
same(50, count($logged('create:/item/')), 'and the factory made a handler for each');
check(count($logged('read:/item/')) >= 50, 'and each handler was read (' . count($logged('read:/item/')) . ' reads)');

unset($app->browser);
Cef::shutdown();
finish();
