# Cookbook

Short recipes for common jobs. Each one builds on [Getting started](getting-started.md) and [Concepts](concepts.md).

The recipes come from one program, [`docs/recipes/cookbook.php`](recipes/cookbook.php). It was run under a virtual X server (`xvfb-run -a php -d extension=build/dist/cefloom.so docs/recipes/cookbook.php`) three times in a row and prints:

```
title: sum=5
evaluate: hello
pdf: true
```

`tests/t_docs.php` runs it with every test run. The pieces below are taken from it, in the order the program uses them.

## Show a page that PHP serves

A custom scheme answers `app://test/...` from PHP, without a network. Register the scheme **before** `Cef::initialize()` and the factory after it. A `ResourceHandler` answers each request ([Handlers](handlers.md#custom-schemes-and-resources) explains the four methods):

```php
Cef::registerCustomScheme('app', SchemeOptions::STANDARD | SchemeOptions::SECURE | SchemeOptions::CORS_ENABLED);
Cef::initialize();
Cef::registerSchemeHandlerFactory('app', 'test', new Factory());

BrowserHost::createBrowser($info, new Printer(), 'app://test/index.html', new BrowserSettings(), null, null);
Cef::run();
```

`Factory::create()` returns a `Page` with the text of the answer; the program keeps the page in the constant `PAGE`.

## Call PHP from the page

Expose the function with `Cef::expose()` and call it with `cefloom.call()`, which returns a `Promise` ([JavaScript bridge](javascript-bridge.md)):

```php
Cef::expose('add', fn(int $a, int $b): int => $a + $b);
```

```js
cefloom.call("add", 2, 3).then(sum => document.title = "sum=" + sum)
```

An exception in the function rejects the promise with the code `500` and the message of the exception.

## React to a change of the page

A `DisplayHandler` is told when the title changes. Here the page sets the title to `sum=5` when `add` has answered, and the handler goes on from there:

```php
public function onTitleChange($browser, $title): void
{
    $this->app->title = $title;
    if ($title === 'sum=5') {
        $this->app->afterSum($browser);
    }
}
```

The `Client` of the browser returns the handler from `getDisplayHandler()`; return the same object every time.

## Run JavaScript and read the result

`executeJavaScript()` returns nothing. Let the script print with `console.log`, and read the line in `DisplayHandler::onConsoleMessage()`:

```php
$browser->getMainFrame()->executeJavaScript('console.log("R:" + document.getElementById("t").textContent)', '', 0);
```

```php
public function onConsoleMessage($browser, $level, $message, $source, $line): bool
{
    if (str_starts_with($message, 'R:')) {
        $this->app->evaluated = substr($message, 2);
        $this->app->save($browser);
    }
    return false;       // false lets CEF log the line as well
}
```

The other way is to expose a function and finish the script with `cefloom.call('result', value)`.

## Save the page as a PDF

`printToPDF()` writes what the browser shows now. Set `scale` and the paper size, because a zero value is not a default ([Printing and PDF](printing.md)). The callback tells whether the file was written:

```php
$settings = new PdfPrintSettings();
$settings->scale = 1.0;
$settings->paperWidth = 8.27;       // inches: A4
$settings->paperHeight = 11.69;
$browser->getHost()->printToPDF($this->pdf, $settings, new Saved($this));
```

```php
public function onPdfPrintFinished(string $path, bool $ok): void
{
    echo 'pdf: ', var_export($ok && filesize($path) > 1000, true), "\n";
    $this->app->finish();
}
```

## Leave the program

`Cef::quit()` ends `Cef::run()`; `Cef::shutdown()` after it releases CEF. CEF can be started once per process, so a program that needs CEF again starts another PHP process.

```php
public function finish(): void
{
    @unlink($this->pdf);
    Cef::quit();
}
```

## Run it without a display

On a machine without a screen, put the program under a virtual X server:

```sh
xvfb-run -a php -d extension=build/dist/cefloom.so docs/recipes/cookbook.php
```

Before `initialize()` the program calls `Cef::addSwitch('ozone-platform', 'x11')`, as the examples do, and `Cef::addSwitch('disable-gpu')`, as the tests do. [Troubleshooting](troubleshooting.md) describes the `Missing X server or $DISPLAY` message.
