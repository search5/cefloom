# Getting started

This walks through `examples/hello.php`: a window that loads a page, in which JavaScript calls a PHP function. Install cefloom first ([Installation](installation.md)).

```sh
php -d extension=build/dist/cefloom.so examples/hello.php        # xvfb-run -a ... on a machine without a display
```

(Drop `-d extension=...` when the extension is enabled in `php.ini`.)

## The program

<!-- docs-test: same-as examples/hello.php -->

```php
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
```

## What each part does

1. **`Cef::addSwitch()`** adds a Chromium command line switch. It must come before `initialize()`. `ozone-platform=x11` makes Chromium use X11 even in a Wayland session.
2. **`Cef::initialize()`** starts CEF **inside this PHP process**: the PHP process is the browser process of CEF. CEF starts its other processes (renderer, GPU, network) from `cefloomsubprocess`, which runs no PHP. It takes optional [`Settings`](configuration.md#settings).
3. **`Cef::expose()`** makes a PHP function callable from the page as `cefloom.call(name, ...args)`, which returns a Promise ([JavaScript bridge](javascript-bridge.md)).
4. **`Client`** is the object you hand to CEF for each browser. CEF asks it for handlers (`getLoadHandler()`, `getDisplayHandler()`, ...); every handler class has the methods CEF calls, and you override the ones you need ([Handlers](handlers.md)). A method you do not override is not called: CEF behaves as if there were no handler.
5. **`BrowserHost::createBrowser()`** opens a browser window. `WindowInfo` says how big, `BrowserSettings` configures it.
6. **`Cef::run()`** is the message loop: it runs until the last browser closes ([The message loop](message-loop.md)). All your handlers run inside it, on the PHP thread.
7. **`Cef::shutdown()`** closes what is left and stops CEF. It must come before the script ends, after you have dropped your references to CEF objects ([Concepts](concepts.md#lifetime-and-shutdown)).

## Next

* A window drawn by CEF itself, with buttons and layout: [Windows with Views](views.md).
* Serving pages from PHP: [Handlers](handlers.md#custom-schemes-and-resources).
* Saving a page as a PDF: [Printing and PDF](printing.md).
