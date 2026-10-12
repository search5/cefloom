# Troubleshooting

## Messages

**`The CEF runtime was not found: ... does not exist.`** The extension is loaded but the runtime is not installed (or is somewhere it does not look). The message lists the places. Run `php bin/cefloom-runtime install ...` ([Installation](installation.md#2-the-cef-runtime)), or set `CEFLOOM_HOME`. `php bin/cefloom-runtime status` shows what is found.

**`The CEF subprocess executable ... was not found`** The runtime directory does not hold `cefloomsubprocess`. Reinstall the runtime with `--force`, or set `browserSubprocessPath` in the `Settings`.

**`Unable to load dynamic library 'cefloom.so'`** The file does not match this PHP: another PHP version, a thread-safe PHP, another architecture, or a musl system. The file name of the release says which (`php8.5`, `nts`, `x86_64`, `glibc`).

**The process stops at start with `Trace/breakpoint trap` or a crash inside `libcef.so`.** Most often an incomplete runtime: a missing or half-copied `locales/` directory (seen when a build copied the runtime wrongly). Check that `<runtime directory>/locales/` holds about 220 `.pak` files and reinstall with `--force`. Also check `ldd <runtime directory>/libcef.so | grep 'not found'`.

**`Missing X server or $DISPLAY`, or no window appears.** There is no display. Use `xvfb-run -a php ...`, or log in to a graphical session. In a Wayland session add `Cef::addSwitch('ozone-platform', 'x11')` before `initialize()`.

**`CEF is already initialized (it can be started once per process)`.** CEF starts once per PHP process, and cannot start again after `Cef::shutdown()`.

**`Command line switches must be added before Cef::initialize()`.** Move `Cef::addSwitch()` and `Cef::registerCustomScheme()` before `initialize()`.

**`The ... object holds no native object (CEF has been shut down)`.** You used a CEF object after `Cef::shutdown()`.

**`cefloom: a CEF thread waited ... ms for the PHP thread`** (on standard error). A [synchronous callback](handlers.md#callbacks-on-other-threads) waited for PHP while the PHP thread sat inside CEF; the wait was cut and CEF's default used for that request. Make the callback unnecessary, or use an asynchronous one.

**Lines like `[...:ERROR:...] Message 0 rejected by interface ...` on standard error.** Chromium's own log. It is usually harmless. `Settings::logSeverity` quiets it.

## Behaviour

**Clicks and typing do nothing in a window.** `externalMessagePump` is on. Leave it off ([The message loop](message-loop.md#polling-and-the-external-pump)).

**The page freezes while PHP works.** PHP's handlers run on the thread that drives the windows. Keep them short, or split the work into small steps with `Cef::postTask()`.

**A second program cannot start: the profile is locked.** Two processes cannot share one `rootCachePath`; give each its own.

## Known problems

* **The media router crashes CEF at shutdown.** After `addObserver()` or `notifyCurrentSinks()`, `Cef::shutdown()` ends the process with a trap. See [Cast](cast.md).
* **Rarely the PHP process does not exit by itself** after the program has finished and printed its last line (seen a few times in testing; the cause is not found). A program that must end can be run under `timeout`, and the test runner stops such a process by itself.
* **Only Linux x86_64, glibc, PHP 8.5 NTS CLI** was built and tested. Not tried: Wayland, a desktop environment's window manager, PHP-FPM, thread-safe PHP, arm64, containers, Windows, macOS, a real Cast device, the print dialog.
