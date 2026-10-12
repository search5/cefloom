# Concepts

## Where PHP runs

cefloom runs CEF **inside the PHP process**. The PHP main thread is CEF's UI thread, and **PHP code runs only on that thread**: your handlers, delegates and exposed functions are all called from inside `Cef::run()` or `Cef::doMessageLoopWork()`. Never block that thread: while a handler runs, the pages and windows do not.

CEF has other threads (IO, file, and others), and some of its callbacks are made there. cefloom never runs PHP on them. For most callbacks it answers CEF at once and runs your PHP later on the PHP thread (resource and scheme handlers, `onBeforeResourceLoad`, `onResourceLoadComplete`, `getAuthCredentials`). A few callbacks need your answer immediately (`getResourceRequestHandler`, `getResourceHandler`, the cookie filters, `onResourceResponse` and similar); for those the CEF thread waits for the PHP thread, and gives up after 5 seconds (`CEFLOOM_SYNC_WAIT_MS`) if the PHP thread is stuck inside CEF, using what CEF does without a handler. [Handlers](handlers.md#callbacks-on-other-threads) lists them.

Only the **command line** (CLI) is supported. PHP-FPM and thread-safe PHP builds were not tried and are not planned.

## Processes

`Cef::initialize()` makes the PHP process the *browser process*. CEF starts more processes from `cefloomsubprocess`: one per page for the renderer, plus GPU and network helpers. They run no PHP. JavaScript runs in the renderer; the [bridge](javascript-bridge.md) carries calls from it to your PHP process.

CEF can be started **once per process**: after `Cef::shutdown()` it cannot be initialized again. A test suite or a long job that needs CEF several times uses one PHP process for each.

## Classes you use

* **Library classes** are implemented by CEF and given to you: `Browser`, `Frame`, `Request`, `Window`, `Value`, and so on. You do not `new` them; you get them from a handler's arguments or a factory (`BrowserView::createBrowserView()`, `Request::create()`). A method of such a class calls CEF.
* **Structs** are plain data with public properties and a constructor: `Settings`, `WindowInfo`, `BrowserSettings`, `Rect`, `Size`, `PdfPrintSettings`. Nested ones are objects too (`$info->bounds->width`). Named arguments work: `new BoxLayoutSettings(horizontal: 1)`.
* **Handler and delegate classes** are the ones you extend to answer CEF: `LoadHandler`, `DisplayHandler`, `WindowDelegate`, `ButtonDelegate`, `ResourceHandler`, `Task`, `MediaObserver`, and so on. Override what you need.
* **Enumerations and constants** are classes of constants in `Cefloom\Types`: `ThreadId::UI`, `ReturnValue::CONTINUE`, `SchemeOptions::STANDARD`.

## What a handler method receives and returns

CEF methods have inputs, a return value and sometimes extra output parameters. A handler method in PHP gets the **inputs in order** and returns the **return value followed by the outputs**:

| The C++ method | The PHP method returns |
| --- | --- |
| returns nothing, no outputs | nothing (`void`) |
| returns a value, no outputs | the value |
| returns a value and one or more outputs, or only outputs | an array, return value first |

For example `ResourceHandler::open(Request $request, Callback $callback)` returns `[handled, handleRequest]`, and `getResponseHeaders(Response $response)` returns `[contentLength, redirectUrl]`. The stub (`php/cefloom.stub.php`) shows the exact signature of every method. A method you do not override is not called at all.

## Lifetime and shutdown

CEF objects are reference counted by CEF; PHP holds them as ordinary objects. Two rules:

* Call **`Cef::shutdown()` before the script ends**, after you have closed your browsers and dropped your own references to `Browser`, `Window` and other CEF objects. `shutdown()` closes the browsers that are left, runs the loop until they are gone (up to 5 seconds), releases the CEF objects PHP still holds and stops CEF.
* A CEF object you still hold after `shutdown()` throws `Error` ("holds no native object (CEF has been shut down)") when used.

If the script ends without `shutdown()`, cefloom shuts CEF down for you when PHP shuts the request down; do not rely on it for scripts that open windows.

## Errors

An exception thrown by a handler never travels into CEF. It stays pending, the callbacks that follow do not run PHP, and PHP throws it when the function that entered CEF returns: `Cef::run()`, `Cef::doMessageLoopWork()`, or the method of a library class you called. `exit()` inside a handler works the same way.

Calling CEF before it is initialized (or without the runtime installed) throws an `Error`.

## Threads and tasks

`Cef::postTask($threadId, new MyTask())` queues work on a CEF thread (`ThreadId::UI`, `IO`, `FILE_BACKGROUND`, ...). The task's `execute()` is PHP, so it always runs on the PHP thread, whatever thread you named; the thread decides only *when* it is queued. `Cef::postDelayedTask()` adds a delay in milliseconds. `Cef::currentlyOn(ThreadId::UI)` is true on the PHP thread.

Work on `ThreadId::FILE_BACKGROUND` is held back by Chromium until a browser has started.
