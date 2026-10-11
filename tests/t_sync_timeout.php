<?php
// A CEF thread that waits for a PHP thread stuck inside the message loop gives up after CEFLOOM_SYNC_WAIT_MS and
// uses CEF's default (llm-wiki, verified-findings F14). The PHP thread is held in a UI thread callback while the
// page asks for a second resource: the IO thread must not wait for ever for getResourceRequestHandler().
require __DIR__ . '/support.php';

use Cefloom\{BrowserHost, BrowserSettings, Buffer, Callback, Cef, Client, DisplayHandler, LifeSpanHandler, Request,
    RequestHandler, ResourceHandler, ResourceRequestHandler, ResourceReadCallback, Response, WindowInfo};

putenv('CEFLOOM_SYNC_WAIT_MS=300');
$GLOBALS['asked'] = [];

final class Page extends ResourceHandler
{
    private string $body = '<html><head><title>x</title></head><body><script>'
        . 'console.log("block");'
        . 'setTimeout(function () { fetch("https://app.test/late").catch(function () { console.log("fetch settled"); }); }, 300);'
        . '</script></body></html>';
    private int $position = 0;

    public function open(Request $request, Callback $callback): array { return [true, true]; }

    public function getResponseHeaders(Response $response): array
    {
        $response->setStatus(200);
        $response->setMimeType('text/html');
        return [strlen($this->body), ''];
    }

    public function read(Buffer $dataOut, ResourceReadCallback $callback): array
    {
        if ($this->position >= strlen($this->body)) {
            return [false, 0];
        }
        $written = $dataOut->write(substr($this->body, $this->position, $dataOut->getSize()));
        $this->position += $written;
        return [true, $written];
    }

    public function cancel(): void {}
}

final class Resources extends ResourceRequestHandler
{
    public function getResourceHandler($browser, $frame, $request): ?ResourceHandler
    {
        return str_ends_with($request->getURL(), '/index.html') ? new Page() : null;
    }
}

final class Requests extends RequestHandler
{
    private Resources $resources;
    public function __construct() { $this->resources = new Resources(); }

    public function getResourceRequestHandler($browser, $frame, $request, $isNavigation, $isDownload, $requestInitiator): array
    {
        $GLOBALS['asked'][] = $request->getURL();
        return [$this->resources, false];
    }
}

final class Display extends DisplayHandler
{
    public array $console = [];
    public function onConsoleMessage($browser, $level, $message, $source, $line): bool
    {
        if ($message === 'block') {
            usleep(1500000);   // the PHP thread stays inside the message loop (this runs in CefDoMessageLoopWork)
        }
        $this->console[] = $message;
        return false;
    }
}

final class Life extends LifeSpanHandler
{
    public bool $created = false;
    public function onAfterCreated($browser): void { $this->created = true; }
}

final class App extends Client
{
    public Display $display;
    public Life $life;
    public Requests $requests;
    public function __construct() { $this->display = new Display(); $this->life = new Life(); $this->requests = new Requests(); }
    public function getDisplayHandler(): ?DisplayHandler { return $this->display; }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
    public function getRequestHandler(): ?RequestHandler { return $this->requests; }
}

start_cef();
$app = new App();
$info = new WindowInfo();
$info->bounds->width = 640;
$info->bounds->height = 480;
check(BrowserHost::createBrowser($info, $app, 'https://app.test/index.html', new BrowserSettings(), null, null), 'createBrowser');
wait_until(fn() => $app->life->created, 'browser created');
wait_until(fn() => in_array('fetch settled', $app->display->console, true), 'the second request ended', 30000);

check(in_array('block', $app->display->console, true), 'the page script ran');
check(in_array('https://app.test/index.html', $GLOBALS['asked'], true), 'the first request was handled by PHP');
check(!in_array('https://app.test/late', $GLOBALS['asked'], true),
    'the request made while the PHP thread was held did not wait for PHP (CEF default was used)');

unset($info);
Cef::shutdown();
same(false, Cef::isRunning(), 'not running after shutdown');
finish();
