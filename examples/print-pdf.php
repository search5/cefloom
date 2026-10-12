<?php
// Save a web page as a PDF file.
//
//   php -d extension=build/dist/cefloom.so examples/print-pdf.php <url> <output.pdf> [--landscape] [--letter]
//   (on a machine without a display: xvfb-run -a php -d extension=... examples/print-pdf.php ...)
//
// The page is loaded in a browser window first: printToPDF() prints what the browser has, so it is called when the
// page has finished loading. The paper is A4 unless --letter is given; the sizes are in inches.

use Cefloom\{BrowserHost, BrowserSettings, Cef, Client, LifeSpanHandler, LoadHandler, PdfPrintCallback, PdfPrintSettings, WindowInfo};

$positional = [];
$landscape = false;
$letter = false;
foreach (array_slice($argv, 1) as $arg) {
    match ($arg) {
        '--landscape' => $landscape = true,
        '--letter' => $letter = true,
        default => $positional[] = $arg,
    };
}
[$url, $output] = $positional + [1 => null];
if ($url === null || $output === null) {
    fwrite(STDERR, "usage: php -d extension=cefloom.so examples/print-pdf.php <url> <output.pdf> [--landscape] [--letter]\n");
    exit(2);
}

/** Called by CEF when the file is written (or could not be). */
final class Done extends PdfPrintCallback
{
    public ?bool $ok = null;
    public function onPdfPrintFinished(string $path, bool $ok): void
    {
        $this->ok = $ok;
        echo $ok ? "wrote $path\n" : "could not write $path\n";
        Cef::quit();
    }
}

final class Printer extends Client
{
    private LoadHandler $load;
    private LifeSpanHandler $life;
    public $browser = null;

    public function __construct(string $output, bool $landscape, bool $letter, public Done $done = new Done())
    {
        $printer = $this;
        $this->life = new class($printer) extends LifeSpanHandler {
            public function __construct(private Printer $printer) {}
            public function onAfterCreated($browser): void { $this->printer->browser = $browser; }
        };
        $this->load = new class($printer, $output, $landscape, $letter) extends LoadHandler {
            private bool $printing = false;
            public function __construct(private Printer $printer, private string $output, private bool $landscape, private bool $letter) {}
            public function onLoadEnd($browser, $frame, $httpStatusCode): void
            {
                if (!$frame->isMain() || $this->printing) {
                    return;
                }
                $this->printing = true;
                $settings = new PdfPrintSettings();
                $settings->landscape = $this->landscape ? 1 : 0;
                $settings->printBackground = 1;
                $settings->scale = 1.0;
                $settings->paperWidth = $this->letter ? 8.5 : 8.27;
                $settings->paperHeight = $this->letter ? 11.0 : 11.69;
                $browser->getHost()->printToPDF($this->output, $settings, $this->printer->done);
            }
        };
    }

    public function getLoadHandler(): ?LoadHandler { return $this->load; }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
}

Cef::addSwitch('ozone-platform', 'x11');
Cef::initialize();

$printer = new Printer($output, $landscape, $letter);
$info = new WindowInfo();
$info->bounds->width = 1024;
$info->bounds->height = 768;
BrowserHost::createBrowser($info, $printer, $url, new BrowserSettings(), null, null);

Cef::run();      // returns when the PDF has been written

$ok = $printer->done->ok === true;
$printer->browser?->getHost()->closeBrowser(true);
unset($info, $printer);
Cef::shutdown();
exit($ok ? 0 : 1);
