<?php
// Shared helpers of the t_browser_* tests (included by them). Run directly it is a smoke test of the helpers.
require_once __DIR__ . '/support.php';

use Cefloom\{BrowserHost, BrowserSettings, Buffer, Callback, Cef, Client, ContextMenuHandler, DisplayHandler, DownloadHandler,
    FindHandler, FocusHandler, JSDialogHandler, KeyboardHandler, LifeSpanHandler, LoadHandler, PermissionHandler, Request,
    RequestHandler, ResourceHandler, ResourceReadCallback, ResourceRequestHandler, Response, WindowInfo};
use Cefloom\Types\{ReturnValue, ThreadId};

const BASE = 'https://app.test';

/** Pages served by Site: path => ['body' => string, 'mime' => 'text/html', 'status' => 200, 'headers' => [name => value]] */
$GLOBALS['site'] = [];
$GLOBALS['site_hits'] = [];

function page(string $path, string $body, string $mime = 'text/html', int $status = 200, array $headers = []): void
{
    $GLOBALS['site'][$path] = ['body' => $body, 'mime' => $mime, 'status' => $status, 'headers' => $headers];
}

function html(string $title, string $body = '', string $script = ''): string
{
    return "<!doctype html><html><head><meta charset=utf-8><title>$title</title></head><body>$body"
        . ($script !== '' ? "<script>$script</script>" : '') . '</body></html>';
}

final class Site extends ResourceHandler
{
    private string $body = '';
    private int $pos = 0;
    public function open(Request $request, Callback $callback): array
    {
        $path = parse_url($request->getURL(), PHP_URL_PATH) ?: '/';
        $GLOBALS['site_hits'][] = $path;
        $e = $GLOBALS['site'][$path] ?? ['body' => 'not found', 'mime' => 'text/plain', 'status' => 404, 'headers' => []];
        $this->entry = $e;
        $this->body = $e['body'];
        return [true, true];
    }
    private array $entry = [];
    public function getResponseHeaders(Response $response): array
    {
        $response->setStatus($this->entry['status']);
        $response->setMimeType($this->entry['mime']);
        foreach ($this->entry['headers'] as $k => $v) {
            $response->setHeaderByName($k, $v, true);
        }
        return [strlen($this->body), ''];
    }
    public function read(Buffer $dataOut, ResourceReadCallback $callback): array
    {
        if ($this->pos >= strlen($this->body)) {
            return [false, 0];
        }
        $n = $dataOut->write(substr($this->body, $this->pos, $dataOut->getSize()));
        $this->pos += $n;
        return [true, $n];
    }
    public function cancel(): void {}
}

class SiteResources extends ResourceRequestHandler
{
    public function getResourceHandler($browser, $frame, $request): ?ResourceHandler
    {
        return str_starts_with($request->getURL(), BASE . '/') ? new Site() : null;
    }
}

class SiteRequests extends RequestHandler
{
    public ResourceRequestHandler $resources;
    public function __construct(?ResourceRequestHandler $r = null) { $this->resources = $r ?? new SiteResources(); }
    public function getResourceRequestHandler($browser, $frame, $request, $isNavigation, $isDownload, $requestInitiator): array
    {
        return [$this->resources, false];
    }
}

/** Records the life of the browser. */
class Life extends LifeSpanHandler
{
    public ?Cefloom\Browser $browser = null;
    public bool $created = false;
    public bool $closed = false;
    public function onAfterCreated($browser): void { $this->browser = $browser; $this->created = true; }
    public function onBeforeClose($browser): void { $this->closed = true; }
}

/** Records navigation events. */
class Load extends LoadHandler
{
    public array $events = [];
    public array $ends = [];       // url => status of every finished main-frame load
    public array $errors = [];
    public int $mainEnds = 0;
    public function onLoadStart($browser, $frame, $transitionType): void { $this->events[] = ['start', $frame->getURL(), $frame->isMain()]; }
    public function onLoadEnd($browser, $frame, $httpStatusCode): void
    {
        $this->events[] = ['end', $frame->getURL(), $frame->isMain(), $httpStatusCode];
        if ($frame->isMain()) {
            $this->mainEnds++;
            $this->ends[$frame->getURL()] = $httpStatusCode;
        }
    }
    public function onLoadError($browser, $frame, $errorCode, $errorText, $failedUrl): void
    {
        $this->errors[] = [$errorCode, $errorText, $failedUrl, $frame->isMain()];
    }
}

/** A client whose handlers are plain public properties. */
class TestClient extends Client
{
    public Life $life;
    public Load $load;
    public SiteRequests $requests;
    public ?DisplayHandler $display = null;
    public ?DownloadHandler $download = null;
    public ?ContextMenuHandler $contextMenu = null;
    public ?FindHandler $find = null;
    public ?FocusHandler $focus = null;
    public ?JSDialogHandler $jsDialog = null;
    public ?KeyboardHandler $keyboard = null;
    public ?PermissionHandler $permission = null;
    public $messages = null;   // callable(Browser, Frame, int, ProcessMessage): bool
    public function __construct()
    {
        $this->life = new Life();
        $this->load = new Load();
        $this->requests = new SiteRequests();
    }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
    public function getRequestHandler(): ?RequestHandler { return $this->requests; }
    public function getDisplayHandler(): ?DisplayHandler { return $this->display; }
    public function getDownloadHandler(): ?DownloadHandler { return $this->download; }
    public function getContextMenuHandler(): ?ContextMenuHandler { return $this->contextMenu; }
    public function getFindHandler(): ?FindHandler { return $this->find; }
    public function getFocusHandler(): ?FocusHandler { return $this->focus; }
    public function getJSDialogHandler(): ?JSDialogHandler { return $this->jsDialog; }
    public function getKeyboardHandler(): ?KeyboardHandler { return $this->keyboard; }
    public function getPermissionHandler(): ?PermissionHandler { return $this->permission; }
    public function onProcessMessageReceived($browser, $frame, $sourceProcess, $message): bool
    {
        return $this->messages ? (bool)($this->messages)($browser, $frame, $sourceProcess, $message) : false;
    }
}

/** Start CEF, create a browser on $url and wait until it exists. */
function open_browser(TestClient $client, string $url = BASE . '/index.html', ?Cefloom\RequestContext $ctx = null): Cefloom\Browser
{
    $info = new WindowInfo();
    $info->bounds->width = 800;
    $info->bounds->height = 600;
    check(BrowserHost::createBrowser($info, $client, $url, new BrowserSettings(), null, $ctx), 'createBrowser');
    wait_until(fn() => $client->life->created, 'browser created');
    return $client->life->browser;
}

/** Navigate the main frame and wait for the load to end. */
function navigate(TestClient $c, string $url): void
{
    $before = $c->load->mainEnds;
    $c->life->browser->getMainFrame()->loadURL($url);
    wait_until(fn() => $c->load->mainEnds > $before, "load of $url");
}

/** Run JS in the main frame; the result is returned through document.title (a console-free round trip). */
function js(TestClient $c, string $code): void { $c->life->browser->getMainFrame()->executeJavaScript($code, '', 0); }

/** Close the browser, drop references, shut CEF down. */
function shutdown_cef(TestClient ...$clients): void
{
    foreach ($clients as $c) {
        $c->life->browser = null;
    }
    Cef::shutdown();
}

function tmp_dir(): string
{
    $d = dirname(__DIR__) . '/build/tmp/bt-' . getmypid();
    @mkdir($d, 0777, true);
    register_shutdown_function(static function () use ($d): void { exec('rm -rf ' . escapeshellarg($d)); });
    return $d;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    page('/index.html', html('smoke', '<p>hi</p>'));
    start_cef();
    $c = new TestClient();
    $b = open_browser($c);
    wait_until(fn() => $c->load->mainEnds > 0, 'loaded');
    same(200, $c->load->ends[BASE . '/index.html'] ?? null, 'served');
    same(true, $b->getMainFrame()->isMain(), 'main frame');
    unset($b);
    shutdown_cef($c);
    finish();
}
