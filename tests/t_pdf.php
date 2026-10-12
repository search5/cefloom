<?php
// Printing: BrowserHost::printToPDF() writes a PDF of the page and reports through a PdfPrintCallback.
require __DIR__ . '/support.php';

use Cefloom\{BrowserHost, BrowserSettings, Cef, Client, LifeSpanHandler, LoadHandler, PdfPrintCallback, PdfPrintSettings, WindowInfo};

final class Printed extends PdfPrintCallback
{
    public ?array $result = null;
    public function onPdfPrintFinished(string $path, bool $ok): void { $this->result = [$path, $ok]; }
}

final class Load extends LoadHandler
{
    public bool $done = false;
    public function onLoadEnd($browser, $frame, $httpStatusCode): void
    {
        if ($frame->isMain()) {
            $this->done = true;
        }
    }
}

final class Life extends LifeSpanHandler
{
    public $browser = null;
    public function onAfterCreated($browser): void { $this->browser = $browser; }
}

final class App extends Client
{
    public Load $load;
    public Life $life;
    public function __construct() { $this->load = new Load(); $this->life = new Life(); }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
}

start_cef();
$app = new App();
$info = new WindowInfo();
$info->bounds->width = 640;
$info->bounds->height = 480;
$html = '<html><head><title>pdf</title></head><body><h1>cefloom pdf test</h1><p>Hello printed world.</p></body></html>';
check(BrowserHost::createBrowser($info, $app, 'data:text/html,' . rawurlencode($html), new BrowserSettings(), null, null), 'createBrowser');
wait_until(fn() => $app->life->browser !== null && $app->load->done, 'page loaded');

$path = sys_get_temp_dir() . '/cefloom-test-' . getmypid() . '.pdf';
register_shutdown_function(static function () use ($path): void { @unlink($path); });
$settings = new PdfPrintSettings();
$settings->landscape = 0;
$settings->printBackground = 1;
$settings->scale = 1.0;
$settings->paperWidth = 8.5;
$settings->paperHeight = 11.0;
$callback = new Printed();
$app->life->browser->getHost()->printToPDF($path, $settings, $callback);
wait_until(fn() => $callback->result !== null, 'the PDF was written', 30000);

same(true, $callback->result[1] ?? null, 'the callback reports success');
same($path, $callback->result[0] ?? null, 'the callback names the file');
$bytes = is_file($path) ? file_get_contents($path) : '';
check(str_starts_with($bytes, '%PDF-'), 'the file is a PDF (' . substr($bytes, 0, 8) . ')');
check(strlen($bytes) > 1000, 'the PDF has content (' . strlen($bytes) . ' bytes)');

unset($info);
Cef::shutdown();
finish();
