<?php
// Handlers: callbacks from CEF threads, outputs, enums, buffers, objects returned to CEF.
require __DIR__ . '/support.php';

use Cefloom\{BrowserHost, BrowserSettings, Buffer, Callback, Cef, Client, DisplayHandler, LifeSpanHandler, LoadHandler,
    Request, RequestHandler, ResourceHandler, ResourceReadCallback, ResourceRequestHandler, Response, WindowInfo};
use Cefloom\Types\{ReturnValue, ThreadId};

$events = [];
$GLOBALS['mainThreadChecks'] = [];

final class Page extends ResourceHandler
{
    private string $body = '<html><head><title>x</title></head><body><h1 id="x">served by php</h1>'
        . '<script>document.title = "t:" + document.getElementById("x").textContent; console.log("script ran");</script>'
        . '</body></html>';
    private int $position = 0;

    public function open(Request $request, Callback $callback): array
    {
        $GLOBALS['mainThreadChecks']['open'] = Cef::currentlyOn(ThreadId::UI);
        return [true, true];                      // [handled, handleRequest]
    }

    public function getResponseHeaders(Response $response): array
    {
        $response->setStatus(200);
        $response->setMimeType('text/html');
        return [strlen($this->body), ''];         // [responseLength, redirectUrl]
    }

    public function read(Buffer $dataOut, ResourceReadCallback $callback): array
    {
        $GLOBALS['mainThreadChecks']['read'] = Cef::currentlyOn(ThreadId::UI);
        if ($this->position >= strlen($this->body)) {
            return [false, 0];
        }
        $chunk = substr($this->body, $this->position, $dataOut->getSize());
        $written = $dataOut->write($chunk);
        $this->position += $written;
        return [true, $written];                  // [ok, bytesRead]
    }

    public function cancel(): void {}
}

final class Resources extends ResourceRequestHandler
{
    public function onBeforeResourceLoad($browser, $frame, $request, $callback): int
    {
        $GLOBALS['events'][] = 'before:' . $request->getURL();
        return ReturnValue::CONTINUE;               // an enum as the return value
    }

    public function getResourceHandler($browser, $frame, $request): ?ResourceHandler
    {
        return str_starts_with($request->getURL(), 'https://app.test/') ? new Page() : null;
    }
}

final class Requests extends RequestHandler
{
    private Resources $resources;
    public function __construct() { $this->resources = new Resources(); }

    public function getResourceRequestHandler($browser, $frame, $request, $isNavigation, $isDownload, $requestInitiator): array
    {
        return [$this->resources, false];          // [handler, disableDefaultHandling]
    }
}

final class Display extends DisplayHandler
{
    public ?string $title = null;
    public array $console = [];
    public function onTitleChange($browser, $title): void { $this->title = $title; }
    public function onConsoleMessage($browser, $level, $message, $source, $line): bool
    {
        $this->console[] = $message;
        return false;
    }
}

final class Life extends LifeSpanHandler
{
    public bool $created = false;
    public bool $closed = false;
    public function onAfterCreated($browser): void { $this->created = true; }
    public function onBeforeClose($browser): void { $this->closed = true; }
}

final class Load extends LoadHandler
{
    public ?int $status = null;
    public function onLoadEnd($browser, $frame, $httpStatusCode): void
    {
        if ($frame->isMain()) {
            $this->status = $httpStatusCode;
        }
    }
}

final class App extends Client
{
    public Display $display;
    public Life $life;
    public Load $load;
    public Requests $requests;
    public function __construct()
    {
        $this->display = new Display();
        $this->life = new Life();
        $this->load = new Load();
        $this->requests = new Requests();
    }
    public function getDisplayHandler(): ?DisplayHandler { return $this->display; }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
    public function getRequestHandler(): ?RequestHandler { return $this->requests; }
}

start_cef();
$app = new App();
$info = new WindowInfo();
$info->bounds->width = 640;
$info->bounds->height = 480;
check(BrowserHost::createBrowser($info, $app, 'https://app.test/index.html', new BrowserSettings(), null, null), 'createBrowser');

wait_until(fn() => $app->life->created, 'browser created');
wait_until(fn() => $app->display->title === 't:served by php', 'page served by the PHP resource handler');
// the title changes before the script logs and before onLoadEnd arrives
wait_until(fn() => $app->load->status !== null && $app->display->console !== [], 'load end and console message');
same(200, $app->load->status, 'load status from the PHP response');
check(in_array('script ran', $app->display->console, true), 'the page script ran (console message)');
check(in_array('before:https://app.test/index.html', $GLOBALS['events'], true), 'onBeforeResourceLoad saw the url');
same(true, $GLOBALS['mainThreadChecks']['open'] ?? null, 'ResourceHandler::open runs on the UI thread (marshalled)');
same(true, $GLOBALS['mainThreadChecks']['read'] ?? null, 'ResourceHandler::read runs on the UI thread (marshalled)');

// close and shut down
$browsers = [];
$app->life->closed = false;
wait_until(fn() => true, 'settle');
unset($info);
Cef::shutdown();
check($app->life->closed, 'onBeforeClose arrived while shutting down');
same(false, Cef::isRunning(), 'not running after shutdown');
finish();
