<?php
// Printing through a PrintHandler that is a fake printer: BrowserHost::print() asks the handler in a fixed order (start, settings, dialog, job,
// reset), the PrintSettings the handler fills in are plain objects that can be tested without a browser, and the callbacks end the job.
// There is no printer and no dialog on the virtual display: the handler stands for both.
require __DIR__ . '/support.php';

use Cefloom\{Range, Browser, BrowserHost, BrowserSettings, Cef, Client, LifeSpanHandler, LoadHandler, PrintDialogCallback, PrintHandler, PrintJobCallback, PrintSettings, Rect, Size, WindowInfo};
use Cefloom\Types\{ColorModel, DuplexMode};

final class Printer extends PrintHandler
{
    public array $calls = [];
    public array $settingsSeen = [];
    public ?string $job = null;
    public string $dialogAnswer = 'continue';      // continue | cancel
    public ?PrintJobCallback $held = null;
    public function onPrintStart(Browser $browser): void { $this->calls[] = 'start'; }
    public function onPrintSettings(Browser $browser, PrintSettings $settings, bool $getDefaults): void
    {
        $this->calls[] = 'settings:' . ($getDefaults ? 'defaults' : 'current');
        // the handler fills in what its printer is: a device, a resolution, two copies, black and white, double sided
        $settings->setDeviceName('Fake Printer');
        $settings->setDPI(300);
        $settings->setCopies(2);
        $settings->setColorModel(ColorModel::GRAY);
        $settings->setDuplexMode(DuplexMode::LONG_EDGE);
        $settings->setCollate(true);
        $settings->setOrientation(false);
        $settings->setPageRanges([new Range(0, 0)]);
        $settings->setPrinterPrintableArea(new Size(2480, 3508), new Rect(0, 0, 2480, 3508), false);
        $this->settingsSeen[] = [$settings->getDeviceName(), $settings->getDPI(), $settings->getCopies(), $settings->getColorModel(), $settings->getDuplexMode()];
    }
    public function onPrintDialog(Browser $browser, bool $hasSelection, PrintDialogCallback $callback): bool
    {
        $this->calls[] = 'dialog:' . ($hasSelection ? 'selection' : 'all');
        if ($this->dialogAnswer === 'cancel') {
            $callback->cancel();
        } else {
            $settings = PrintSettings::create();
            $settings->setDeviceName('Chosen Printer');
            $settings->setCopies(3);
            $callback->continue($settings);
        }
        return true;
    }
    public function onPrintJob(Browser $browser, string $documentName, string $pdfFilePath, PrintJobCallback $callback): bool
    {
        $this->calls[] = 'job';
        $this->job = $pdfFilePath;
        $this->jobName = $documentName;
        $this->jobHead = is_file($pdfFilePath) ? file_get_contents($pdfFilePath, false, null, 0, 5) : null;
        $callback->continue();
        return true;
    }
    public ?string $jobName = null;
    public ?string $jobHead = null;
    public function onPrintReset(Browser $browser): void { $this->calls[] = 'reset'; }
    public function getPdfPaperSize(Browser $browser, int $deviceUnitsPerInch): Size { $this->calls[] = 'paper:' . $deviceUnitsPerInch; return new Size(595, 842); }
}
final class Life extends LifeSpanHandler
{
    public $browser = null;
    public function onAfterCreated($browser): void { $this->browser = $browser; }
}
final class Load extends LoadHandler
{
    public int $ends = 0;
    public function onLoadEnd($browser, $frame, $httpStatusCode): void { if ($frame->isMain()) { $this->ends++; } }
}
final class App extends Client
{
    public Printer $printer;
    public Life $life;
    public Load $load;
    public function __construct() { $this->printer = new Printer(); $this->life = new Life(); $this->load = new Load(); }
    public function getPrintHandler(): ?PrintHandler { return $this->printer; }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
}

// ---- PrintSettings as an object
$settings = PrintSettings::create();
check($settings !== null && $settings->isValid() && !$settings->isReadOnly(), 'PrintSettings::create gives a valid, writable object');
$settings->setDeviceName('Printer A');
$settings->setDPI(600);
$settings->setCopies(4);
$settings->setColorModel(ColorModel::COLOR);
$settings->setDuplexMode(DuplexMode::SHORT_EDGE);
$settings->setCollate(true);
$settings->setSelectionOnly(true);
$settings->setOrientation(true);
$settings->setPageRanges([new Range(0, 1), new Range(4, 4)]);
same(['Printer A', 600, 4, ColorModel::COLOR, DuplexMode::SHORT_EDGE], [$settings->getDeviceName(), $settings->getDPI(), $settings->getCopies(), $settings->getColorModel(), $settings->getDuplexMode()], 'device, DPI, copies, color model and duplex mode are kept');
same([true, true, true], [$settings->willCollate(), $settings->isSelectionOnly(), $settings->isLandscape()], 'collate, selection only and landscape are kept');
same([[0, 1], [4, 4]], array_map(fn($r) => [$r->from, $r->to], $settings->getPageRanges()), 'page ranges are kept');
same(2, $settings->getPageRangesCount(), 'and counted');
$settings->setOrientation(false);
$settings->setSelectionOnly(false);
same([false, false], [$settings->isLandscape(), $settings->isSelectionOnly()], 'and can be turned off');

// ---- print() on a browser
$base = start_http_server();
start_cef();
$app = new App();
$info = new WindowInfo();
$info->bounds->width = 400;
$info->bounds->height = 300;
BrowserHost::createBrowser($info, $app, "$base/page?title=printme&body=" . rawurlencode('<h1>print me</h1>'), new BrowserSettings(), null, null);
wait_until(fn() => $app->life->browser !== null && $app->load->ends > 0, 'the page');
$host = $app->life->browser->getHost();
$printer = $app->printer;

// In the Chrome runtime (the only one this build has) print() opens Chrome's own print preview and does not go through the PrintHandler: the
// handler is never asked (K39). This test keeps that as it is; if CEF starts calling the handler, it fails and the flow below can be tested.
$host->print();
pump(3.0);
same([], $printer->calls, 'BrowserHost::print() does not call the PrintHandler in the Chrome runtime (K39)');
$host->closeBrowser(true);
pump(0.5);

Cef::shutdown();
finish();
