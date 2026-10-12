<?php
// The recipes of docs/cookbook.md in one program: a page served by PHP, a PHP function called from the page,
// JavaScript run from PHP with its result read back, and the page saved as a PDF.
//
//   xvfb-run -a php -d extension=build/dist/cefloom.so docs/recipes/cookbook.php

use Cefloom\{Browser, BrowserHost, BrowserSettings, Buffer, Callback, Cef, Client, DisplayHandler, Frame, LoadHandler};
use Cefloom\{PdfPrintCallback, PdfPrintSettings, Request, ResourceHandler, ResourceReadCallback, Response, SchemeHandlerFactory, WindowInfo};
use Cefloom\Types\SchemeOptions;

const PAGE = '<!doctype html><title>start</title><p id="t">hello</p>'
    . '<script>cefloom.call("add", 2, 3).then(sum => document.title = "sum=" + sum)</script>';

/** Answer every request of the scheme with one string. */
final class Page extends ResourceHandler
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
        $written = $dataOut->write(substr($this->body, $this->position, $dataOut->getSize()));
        $this->position += $written;
        return [true, $written];
    }

    public function cancel(): void {}
}

final class Factory extends SchemeHandlerFactory
{
    public function create(?Browser $browser, ?Frame $frame, string $schemeName, Request $request): ?ResourceHandler
    {
        return new Page(PAGE, 'text/html');
    }
}

/** Receives the answer of printToPDF(). */
final class Saved extends PdfPrintCallback
{
    public function __construct(private Printer $app) {}

    public function onPdfPrintFinished(string $path, bool $ok): void
    {
        echo 'pdf: ', var_export($ok && filesize($path) > 1000, true), "\n";
        $this->app->finish();
    }
}

final class Printer extends Client
{
    public string $title = '';
    public ?string $evaluated = null;
    public string $pdf;
    private LoadHandler $load;
    private DisplayHandler $display;

    public function __construct()
    {
        $this->pdf = sys_get_temp_dir() . '/cookbook-' . getmypid() . '.pdf';
        $app = $this;
        $this->display = new class($app) extends DisplayHandler {
            public function __construct(private Printer $app) {}

            public function onTitleChange($browser, $title): void
            {
                $this->app->title = $title;
                if ($title === 'sum=5') {                      // the page has called add(2, 3) in PHP
                    $this->app->afterSum($browser);
                }
            }

            public function onConsoleMessage($browser, $level, $message, $source, $line): bool
            {
                if (str_starts_with($message, 'R:')) {         // the result of the script that PHP ran
                    $this->app->evaluated = substr($message, 2);
                    $this->app->save($browser);
                }
                return false;
            }
        };
        $this->load = new class extends LoadHandler {};
    }

    public function getDisplayHandler(): ?DisplayHandler { return $this->display; }

    public function getLoadHandler(): ?LoadHandler { return $this->load; }

    public function afterSum(Browser $browser): void
    {
        echo "title: $this->title\n";
        $browser->getMainFrame()->executeJavaScript('console.log("R:" + document.getElementById("t").textContent)', '', 0);
    }

    public function save(Browser $browser): void
    {
        echo "evaluate: $this->evaluated\n";
        $settings = new PdfPrintSettings();
        $settings->scale = 1.0;
        $settings->paperWidth = 8.27;
        $settings->paperHeight = 11.69;
        $browser->getHost()->printToPDF($this->pdf, $settings, new Saved($this));
    }

    public function finish(): void
    {
        @unlink($this->pdf);
        Cef::quit();
    }
}

Cef::addSwitch('ozone-platform', 'x11');
Cef::addSwitch('disable-gpu');
Cef::registerCustomScheme('app', SchemeOptions::STANDARD | SchemeOptions::SECURE | SchemeOptions::CORS_ENABLED);
Cef::initialize();
Cef::registerSchemeHandlerFactory('app', 'test', new Factory());
Cef::expose('add', fn(int $a, int $b): int => $a + $b);

$info = new WindowInfo();
$info->bounds->width = 800;
$info->bounds->height = 600;
BrowserHost::createBrowser($info, new Printer(), 'app://test/index.html', new BrowserSettings(), null, null);
Cef::run();
Cef::shutdown();
