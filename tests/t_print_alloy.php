<?php
// The PrintHandler as a fake printer, in the Alloy runtime style (WindowInfo::runtimeStyle = 2): the Chrome style prints through Chrome's own
// preview and never asks the handler (K39, t_print.php). BrowserHost::print() asks the handler in a fixed order (start, settings, dialog,
// job, reset) and the callbacks end the job. There is no printer and no dialog on the virtual display: the handler stands for both.
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
    public static function fill(PrintSettings $settings, string $device, int $copies): void
    {
        $settings->setDeviceName($device);
        $settings->setDPI(300);
        $settings->setCopies($copies);
        $settings->setColorModel(ColorModel::GRAY);
        $settings->setDuplexMode(DuplexMode::LONG_EDGE);
        $settings->setCollate(true);
        $settings->setOrientation(false);
        $settings->setPageRanges([new Range(0, 0)]);
        $settings->setPrinterPrintableArea(new Size(2480, 3508), new Rect(0, 0, 2480, 3508), false);
    }
    public function onPrintStart(Browser $browser): void { $this->calls[] = 'start'; }
    public function onPrintSettings(Browser $browser, PrintSettings $settings, bool $getDefaults): void
    {
        $this->calls[] = 'settings:' . ($getDefaults ? 'defaults' : 'current');
        // the handler fills in what its printer is: a device, a resolution, two copies, black and white, double sided
        self::fill($settings, 'Fake Printer', 2);
        $this->settingsSeen[] = [$settings->getDeviceName(), $settings->getDPI(), $settings->getCopies(), $settings->getColorModel(), $settings->getDuplexMode()];
    }
    public function onPrintDialog(Browser $browser, bool $hasSelection, PrintDialogCallback $callback): bool
    {
        $this->calls[] = 'dialog:' . ($hasSelection ? 'selection' : 'all');
        if ($this->dialogAnswer === 'cancel') {
            $callback->cancel();
        } else {
            $settings = PrintSettings::create();
            self::fill($settings, 'Chosen Printer', 3);
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


$base = start_http_server();
// The out of process print service renders the job itself and fails on a printer that does not exist; the handler needs the old path.
Cef::addSwitch('disable-features', 'EnableOopPrintDrivers');
start_cef();
$app = new App();
$info = new WindowInfo();
$info->bounds->width = 400;
$info->bounds->height = 300;
$info->runtimeStyle = 2;
BrowserHost::createBrowser($info, $app, "$base/page?title=printme&body=" . rawurlencode('<h1>print me</h1>'), new BrowserSettings(), null, null);
wait_until(fn() => $app->life->browser !== null && $app->load->ends > 0, 'the page');
$host = $app->life->browser->getHost();
$printer = $app->printer;
same(2, $host->getRuntimeStyle(), 'the browser has the Alloy style');

// ---- the flow with a dialog that continues
$host->print();
wait_until(fn() => in_array('reset', $printer->calls, true), 'the print flow ends', 15000);
same(['start', 'settings:defaults', 'dialog:all', 'job', 'reset'], $printer->calls, 'print() asks the handler in a fixed order: start, settings, dialog, job, reset');
same([['Fake Printer', 300, 2, ColorModel::GRAY, DuplexMode::LONG_EDGE]], $printer->settingsSeen, 'onPrintSettings gets a PrintSettings that the handler fills in');
same('%PDF-', $printer->jobHead, 'onPrintJob gets the path of a PDF');
same('printme', $printer->jobName, 'and the name of the document is the title of the page');

// ---- a dialog that cancels: no job
$printer->calls = [];
$printer->dialogAnswer = 'cancel';
$host->print();
wait_until(fn() => in_array('reset', $printer->calls, true), 'the cancelled flow ends', 15000);
same(['start', 'settings:defaults', 'dialog:all', 'reset'], $printer->calls, 'a dialog that cancels ends the flow without a job');

$host->closeBrowser(true);
pump(0.5);
Cef::shutdown();
finish();
