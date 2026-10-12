<?php
// The runtime that every generated class stands on (ext/runtime/cl_runtime.cc): time values, string maps, Buffer, the
// PHP object a CEF object is mapped back to, and the lifetime of objects across browsers that are created and closed.
require __DIR__ . '/support.php';

use Cefloom\{Browser, BrowserHost, BrowserSettings, Buffer, Cef, Client, Cookie, LifeSpanHandler, Request, RequestHandler,
    ResourceRequestHandler, Response, ResponseFilter, WindowInfo};
use Cefloom\Types\ResponseFilterStatus;

// ---- time: DateTimeInterface, int and float seconds go in, a DateTimeImmutable (or null) comes out
$cookie = fn($v) => new Cookie('a', 'b', 'd', '/', false, false, null, null, true, $v);
$at = fn($v) => ($c = $cookie($v))->expires;
same('1700000000.000000', $at(1700000000)->format('U.u'), 'an int is seconds since the epoch');
same('1700000000.500000', $at(1700000000.5)->format('U.u'), 'a float keeps the fraction');
same('1893553445.000000', $at(new DateTime('2030-01-02 03:04:05 UTC'))->format('U.u'), 'a DateTime is converted');
same(1234567890, $at(new DateTimeImmutable('@1234567890'))->getTimestamp(), 'a DateTimeImmutable is kept');
check($at(1700000000) instanceof DateTimeImmutable, 'what comes out is always a DateTimeImmutable');
same(null, $at(null), 'null stays null');
foreach (['text' => 'x', 'array' => [], 'object' => new stdClass] as $what => $bad) {
    throws(fn() => $cookie($bad), TypeError::class, "a $what is not a time");
}

// ---- string maps (headers) in both directions
$request = Request::create();
$request->setHeaderMap(['X-A' => '1', 'X-B' => '2']);
same(['X-A' => '1', 'X-B' => '2'], $request->getHeaderMap(), 'a header map comes back as it went in');
same('1', $request->getHeaderByName('x-a'), 'a header is found whatever the case of its name');
$request->setHeaderMap([]);
same([], $request->getHeaderMap(), 'an empty map clears the headers');
$request->setHeaderMap(['n' => 5]);
same(['n' => '5'], $request->getHeaderMap(), 'values are converted to text');
$response = Response::create();
$response->setHeaderByName('Foo', 'bar', true);
same(['Foo' => 'bar'], $response->getHeaderMap(), 'the headers of a response');
$response->setHeaderByName('Foo', 'baz', true);
$response->setHeaderByName('Foo', 'qux', false);
same(true, isset($response->getHeaderMap()['Foo']), 'a header can be overridden or added to');

// ---- Buffer: seen from a response filter, the one place a script gets one in this API
$GLOBALS['seen'] = [];
final class Probe extends ResponseFilter
{
    public ?Buffer $kept = null;
    public function initFilter(): bool { return true; }
    public function filter(?Buffer $dataIn, Buffer $dataOut): array
    {
        $s = &$GLOBALS['seen'];
        if ($dataIn === null) {
            return [ResponseFilterStatus::DONE, 0, 0];
        }
        $this->kept ??= $dataIn;
        $s['valid'] = [$dataIn->isValid(), $dataOut->isValid()];
        $s['writable'] = [$dataIn->isWritable(), $dataOut->isWritable()];
        $n = $dataIn->getSize();
        $s['size'] = $n;
        $s['read_all'] = $dataIn->read(0, $n);
        $s['read_part'] = $dataIn->read(1, 3);
        $s['read_zero'] = $dataIn->read(0, 0);
        $s['too_long'] = $dataIn->read(0, $n + 100);       // clamped to what there is
        $s['at_end'] = $dataIn->read($n, 5);               // the end is a valid offset: nothing to read
        $s['out_size'] = $dataOut->getSize();
        $s['write_over'] = $dataOut->write(str_repeat('x', $dataOut->getSize() + 10), 0);   // cut at the end: the count written
        foreach (['past_end' => fn() => $dataIn->read($n + 1, 1), 'negative' => fn() => $dataIn->read(-1, 1),
                  'write_past_end' => fn() => $dataOut->write('x', $dataOut->getSize() + 1)] as $k => $f) {
            try { $f(); $s['err'][$k] = null; } catch (Throwable $e) { $s['err'][$k] = get_class($e); }
        }
        $take = min($n, $dataOut->getSize());
        $dataOut->write(substr($s['read_all'], 0, $take), 0);
        return [ResponseFilterStatus::NEED_MORE_DATA, $take, $take];
    }
}
final class Res extends ResourceRequestHandler
{
    public Probe $probe;
    public function __construct() { $this->probe = new Probe(); }
    public function getResourceResponseFilter($browser, $frame, $request, $response): ?ResponseFilter
    {
        return str_ends_with(parse_url($request->getURL(), PHP_URL_PATH), '/hello') ? $this->probe : null;
    }
}
final class Req extends RequestHandler
{
    public Res $res;
    public function __construct() { $this->res = new Res(); }
    public function getResourceRequestHandler($browser, $frame, $request, $isNavigation, $isDownload, $requestInitiator): array { return [$this->res, false]; }
}
final class Life extends LifeSpanHandler
{
    public array $browsers = [];
    public int $closed = 0;
    public function onAfterCreated($browser): void { $this->browsers[] = $browser; }
    public function onBeforeClose($browser): void { $this->closed++; }
}
final class App extends Client
{
    public Life $life;
    public Req $req;
    public function __construct() { $this->life = new Life(); $this->req = new Req(); }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
    public function getRequestHandler(): ?RequestHandler { return $this->req; }
}
function open(App $app, string $url): void
{
    $info = new WindowInfo();
    $info->bounds->width = 200;
    $info->bounds->height = 150;
    BrowserHost::createBrowser($info, $app, $url, new BrowserSettings(), null, null);
}

$base = start_http_server();
start_cef();
$app = new App();
open($app, "$base/hello");
wait_until(fn() => isset($GLOBALS['seen']['size']) && $app->life->browsers, 'the filter ran');
$s = $GLOBALS['seen'];
same([true, true], $s['valid'], 'both buffers are valid during the callback');
check($s['writable'][1], 'the output buffer is writable');
same(['hello', 5], [$s['read_all'], $s['size']], 'read(0, size) is the data');
same(['ell', ''], [$s['read_part'], $s['read_zero']], 'read(offset, length) and an empty read');
same(['hello', ''], [$s['too_long'], $s['at_end']], 'a length past the end is cut, an offset at the end reads nothing');
same($s['out_size'], $s['write_over'], 'a write is cut at the end of the buffer and returns what it wrote');
foreach (['past_end', 'negative', 'write_past_end'] as $k) {
    same(ValueError::class, $s['err'][$k] ?? null, "buffer: an offset out of range ($k) is a ValueError, not a read or write past the memory");
}
check($app->req->res->probe->kept->isValid() === false, 'a buffer is invalid once the callback is over');
throws(fn() => $app->req->res->probe->kept->read(0, 1), Error::class, 'and reading it throws');

// ---- the PHP object of a CEF object
$browser = $app->life->browsers[0];
check($browser->getHost()->getClient() === $app, 'BrowserHost::getClient is the very object that was given');
check($browser->getHost()->getBrowser()->isSame($browser), 'Host::getBrowser is the same browser (a library object is a new PHP object on each call: isSame compares)');

// ---- lifetime: browsers created and closed again, one after another
$before = memory_get_usage();
for ($i = 0; $i < 6; $i++) {
    $a = new App();
    open($a, "$base/hello?round=$i");
    wait_until(fn() => count($a->life->browsers) === 1, "browser $i");
    $a->life->browsers[0]->getHost()->closeBrowser(true);
    wait_until(fn() => $a->life->closed === 1, "browser $i closed");
    unset($a);
}
gc_collect_cycles();
$grown = memory_get_usage() - $before;
check($grown < 2 * 1024 * 1024, "PHP memory after six browsers created and closed: +$grown bytes");

$app->life->browsers[0]->getHost()->closeBrowser(true);
wait_until(fn() => $app->life->closed === 1, 'the first browser closed');
Cef::shutdown();
finish();
