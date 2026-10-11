<?php
// A custom scheme registered by PHP is known to every CEF process and served by a PHP SchemeHandlerFactory.
require __DIR__ . '/support.php';

use Cefloom\{BrowserHost, BrowserSettings, Buffer, Callback, Cef, Client, DisplayHandler, Frame, Browser, LifeSpanHandler,
    Request, ResourceHandler, ResourceReadCallback, Response, SchemeHandlerFactory, WindowInfo};
use Cefloom\Types\SchemeOptions;

final class Doc extends ResourceHandler
{
    private int $position = 0;
    public function __construct(private string $body, private string $mime) {}
    public function open(Request $request, Callback $callback): array { return [true, true]; }
    public function getResponseHeaders(Response $response): array
    {
        $response->setStatus(200);
        $response->setMimeType($this->mime);
        return [strlen($this->body), ''];
    }
    public function read(Buffer $dataOut, ResourceReadCallback $callback): array
    {
        if ($this->position >= strlen($this->body)) {
            return [false, 0];
        }
        $n = $dataOut->write(substr($this->body, $this->position, $dataOut->getSize()));
        $this->position += $n;
        return [true, $n];
    }
    public function cancel(): void {}
}

final class Factory extends SchemeHandlerFactory
{
    public array $urls = [];
    public function create(?Browser $browser, ?Frame $frame, string $schemeName, Request $request): ?ResourceHandler
    {
        $url = $request->getURL();
        $this->urls[] = $url;
        if (str_ends_with($url, '/app.js')) {
            return new Doc('document.title = "js:" + location.protocol;', 'text/javascript');
        }
        return new Doc('<html><body>custom scheme<script src="app://test/app.js"></script></body></html>', 'text/html');
    }
}

final class Display extends DisplayHandler
{
    public ?string $title = null;
    public function onTitleChange($browser, $title): void { $this->title = $title; }
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
    public function __construct() { $this->display = new Display(); $this->life = new Life(); }
    public function getDisplayHandler(): ?DisplayHandler { return $this->display; }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
}

Cef::registerCustomScheme('app', SchemeOptions::STANDARD | SchemeOptions::SECURE | SchemeOptions::CORS_ENABLED);
throws(fn() => Cef::registerCustomScheme('a:b'), ValueError::class, 'scheme names are checked');
start_cef();
throws(fn() => Cef::registerCustomScheme('late'), Error::class, 'too late after initialize');

$factory = new Factory();
check(Cef::registerSchemeHandlerFactory('app', 'test', $factory), 'registerSchemeHandlerFactory');
$app = new App();
BrowserHost::createBrowser(new WindowInfo(), $app, 'app://test/index.html', new BrowserSettings(), null, null);
wait_until(fn() => $app->display->title === 'js:app:', 'the page loaded its script through the custom scheme');
check(in_array('app://test/index.html', $factory->urls, true), 'the factory saw the page');
check(in_array('app://test/app.js', $factory->urls, true), 'the factory saw the script');
Cef::shutdown();
finish();
