<?php
// The smallest cefloom program: open a window, load a page, call PHP from JavaScript.
//
//   php -d extension=build/dist/cefloom.so examples/hello.php
//   (on a machine without a display: xvfb-run -a php -d extension=... examples/hello.php)

use Cefloom\{BrowserHost, BrowserSettings, Cef, Client, DisplayHandler, LifeSpanHandler, LoadHandler, WindowInfo};

final class Load extends LoadHandler
{
    public function onLoadEnd($browser, $frame, $httpStatusCode): void
    {
        if ($frame->isMain()) {
            echo "loaded, status $httpStatusCode\n";
            $frame->executeJavaScript('cefloom.call("add", 2, 3).then(r => console.log("add=" + r))', '', 0);
        }
    }
}

final class Display extends DisplayHandler
{
    public function onConsoleMessage($browser, $level, $message, $source, $line): bool
    {
        echo "console: $message\n";
        if (str_starts_with($message, 'add=')) {
            $browser->getHost()->closeBrowser(true);
        }
        return false;
    }
}

final class App extends Client
{
    private Load $load;
    private Display $display;

    public function __construct()
    {
        $this->load = new Load();
        $this->display = new Display();
    }

    public function getLoadHandler(): ?LoadHandler { return $this->load; }
    public function getDisplayHandler(): ?DisplayHandler { return $this->display; }
}

Cef::addSwitch('ozone-platform', 'x11');
Cef::initialize();
Cef::expose('add', fn(int $a, int $b): int => $a + $b);

$info = new WindowInfo();
$info->bounds->width = 800;
$info->bounds->height = 600;
BrowserHost::createBrowser($info, new App(), 'data:text/html,<h1>hello from cefloom</h1>', new BrowserSettings(), null, null);

Cef::run();       // returns when the last browser is closed
Cef::shutdown();
echo "done\n";
