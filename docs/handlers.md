# Handlers

CEF reports what happens through **handlers**. You give CEF a `Client`; CEF asks it for the handlers it wants, and calls their methods. The methods you do not override are not called, and CEF acts as if there were no handler.

```php
final class App extends Client
{
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
    public function getDisplayHandler(): ?DisplayHandler { return $this->display; }
}
```

The `Client` can return, among others: `getLifeSpanHandler()`, `getLoadHandler()`, `getDisplayHandler()`, `getRequestHandler()`, `getKeyboardHandler()`, `getFocusHandler()`, `getContextMenuHandler()`, `getDownloadHandler()`, `getDialogHandler()`, `getJSDialogHandler()`, `getPermissionHandler()`, `getFindHandler()`, `getDragHandler()`, `getRenderHandler()`, `getPrintHandler()`, `getAudioHandler()`, `getCommandHandler()`, `getFrameHandler()`. Return the same handler object every time (keep it in a property); CEF may ask often.

Return values and arguments follow one rule ([Concepts](concepts.md#what-a-handler-method-receives-and-returns)): inputs in order; the return value, followed by the outputs, comes back as an array when there are several.

## The ones you will use first

| Handler | Methods you will want |
| --- | --- |
| `LifeSpanHandler` | `onAfterCreated($browser)`, `onBeforeClose($browser)`, `onBeforePopup(...)` |
| `LoadHandler` | `onLoadingStateChange($browser, $isLoading, $canGoBack, $canGoForward)`, `onLoadEnd($browser, $frame, $httpStatusCode)`, `onLoadError(...)` |
| `DisplayHandler` | `onTitleChange($browser, $title)`, `onConsoleMessage($browser, $level, $message, $source, $line)` (return `false` to let CEF log it too) |
| `RequestHandler` | `getResourceRequestHandler(...)`, `getAuthCredentials(...)` |

## Custom schemes and resources

PHP can serve the content of a page: register a scheme before `initialize()`, give CEF a factory for it after, and answer each request with a `ResourceHandler`.

```php
use Cefloom\{Browser, Buffer, Callback, Cef, Frame, Request, ResourceHandler, ResourceReadCallback, Response, SchemeHandlerFactory};
use Cefloom\Types\SchemeOptions;

final class Page extends ResourceHandler
{
    private int $position = 0;
    public function __construct(private string $body, private string $mime) {}

    // [handled, handleRequest]: true, true = "mine, and I can answer now"
    public function open(Request $request, Callback $callback): array { return [true, true]; }

    // [content length, redirect URL]; the response object takes the status and headers
    public function getResponseHeaders(Response $response): array
    {
        $response->setStatus(200);
        $response->setMimeType($this->mime);
        return [strlen($this->body), ''];
    }

    // Called until the whole body is read: [ok, bytes written]; [false, 0] when there is no more
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
        return new Page('<h1>from PHP</h1>', 'text/html');
    }
}

Cef::registerCustomScheme('app', SchemeOptions::STANDARD | SchemeOptions::SECURE | SchemeOptions::CORS_ENABLED);  // before initialize()
Cef::initialize();
Cef::registerSchemeHandlerFactory('app', 'test', new Factory());      // app://test/... is now served by PHP
```

`tests/t_scheme.php` runs this. To serve ordinary `https://` addresses from PHP, return a `ResourceRequestHandler` from `RequestHandler::getResourceRequestHandler()`, and a `ResourceHandler` from its `getResourceHandler()` for the URLs you want (return `null` for the others: they go to the network); `tests/t_handlers.php` does it.

Your `open()`, `getResponseHeaders()`, `read()`, `skip()` and `cancel()` always run on the PHP thread. cefloom calls `getResponseHeaders()` as soon as `open()` has said the request is handled (right after it returns `[true, true]`, or when you call `$callback->continue()`), and passes the answer on when CEF asks. The `Buffer` that `read()` receives is valid only while `read()` runs: write the data into it before returning.

## Callbacks on other threads

CEF makes some callbacks on its IO thread, and CEF's IO thread must not wait for PHP for long (it also carries the browser process's inter-process messages; waiting there can freeze the whole browser). cefloom therefore handles them like this:

| | Callbacks | What cefloom does |
| --- | --- | --- |
| **Asynchronous**: answered at once, your PHP runs later on the PHP thread | `ResourceHandler` (`open`, `processRequest`, `getResponseHeaders`, `skip`, `read`, `cancel`), `SchemeHandlerFactory::create`, `ResourceRequestHandler::onBeforeResourceLoad` and `onResourceLoadComplete`, `RequestHandler::getAuthCredentials` | `onBeforeResourceLoad` may return `ReturnValue::CONTINUE_ASYNC` and call `$callback` later; `getAuthCredentials` returns `true` and calls the callback, or `false` to cancel |
| **Synchronous**: CEF needs your answer now | `getResourceRequestHandler`, `getResourceHandler`, `getCookieAccessFilter`, `canSendCookie`, `canSaveCookie`, `onResourceResponse`, `onResourceRedirect`, `onProtocolExecution`, the response filter methods, `ResourceHandler::readResponse` | The CEF thread waits for your PHP. If the PHP thread is stuck inside CEF for more than 5 seconds (`CEFLOOM_SYNC_WAIT_MS`), the wait ends, a message is written to standard error, and CEF's default is used for that request |

Implement the synchronous ones only when you must, and keep them short. A page that is being drawn while a synchronous callback waits can deadlock the browser until the 5 seconds are over.

## Tasks

`Cef::postTask($threadId, new MyTask())` queues a `Task` ([Concepts](concepts.md#threads-and-tasks)); it is the way to run PHP "later" from a handler, or after a delay with `Cef::postDelayedTask()`.
