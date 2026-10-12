# The message loop

CEF does its work when PHP lets it: PHP calls the message loop, and everything CEF wants to tell you (every handler, delegate and exposed function) happens inside that call, on the PHP thread. There are two ways to run it.

## `Cef::run()`

```php
Cef::run();                                  // until the last browser closes, or Cef::quit()
Cef::run(fn() => doSomething());             // $tick is called after every iteration
Cef::run($tick, quitWhenLastBrowserCloses: false);  // keep running with no browser open
```

`run()` returns when `Cef::quit()` is called, when `$tick` returns `false`, or (by default) when the last browser has closed. Windows made with Views end the loop when you call `Cef::quit()` from `WindowDelegate::onWindowDestroyed()`, as `examples/views.php` does.

## Your own loop

```php
while ($running) {
    Cef::doMessageLoopWork();      // runs CEF's pending work, and your handlers
    // ... your own work, kept short ...
    Cef::wait(5);                  // sleep until CEF wants to run again, at most 5 ms
}
```

Keep each turn short: a handler or your own work that takes a second freezes every window and page for that second. `Cef::wait($maxMilliseconds)` sleeps, but wakes up early when CEF announces work (when the external pump is on).

## Polling and the external pump

CEF can be driven in two ways, chosen with `Settings::externalMessagePump`:

| | Polling (**the default**) | External pump (`externalMessagePump = 1`) |
| --- | --- | --- |
| How | Your loop calls `doMessageLoopWork()` at least every few milliseconds; `Cef::run()` and `Cef::wait()` wait at most 5 ms | CEF announces when it has work (`OnScheduleMessagePumpWork`); the waits can be long |
| Windows made by CEF (Views, `WindowInfo` windows) | **Receive mouse and keyboard input** | **Receive no mouse or keyboard input**: CEF runs only the work it announced and never reads the X11 socket |
| Idle CPU | measured 3.2 % of one core for the PHP process in a 5 s idle run | 2.7 % |
| Suited to | everything with windows made by CEF | pages without a window of CEF's own (off-screen rendering, or a toolkit that owns the window and its events) |

The idle figures are for the PHP process only (CEF's helper processes are not included) and come from one measurement.

```php
$settings = new Cefloom\Settings();
$settings->externalMessagePump = 1;
Cef::initialize($settings);
```

## Stopping

`Cef::quit()` makes `run()` return; it can be called from any handler. Then close the browsers and call `Cef::shutdown()` ([Concepts](concepts.md#lifetime-and-shutdown)).
