# Limitations and known constraints

What does not work yet, and the known constraints. Each item says whether the cause was confirmed. Problems that were fixed are not listed. The design notes behind most items are in `llm-wiki/pages/reference/known-constraints.md`.

## Platforms

| Item | Status |
| --- | --- |
| Linux x86_64, glibc | The only platform that was built and tested, on Ubuntu with PHP 8.5 (NTS, CLI) and CEF 154.0.34 ([Installation](installation.md#requirements)). |
| Other glibc distributions | Not tried. |
| musl (Alpine) | Not supported: CEF is built for glibc. |
| arm64 | Built the same way, not tried. |
| Windows, macOS | Not tried, not started. |
| PHP versions other than 8.5 | Not tried. |
| Thread-safe PHP | Not tried. |
| PHP-FPM and other servers | Not tried and not planned; only the command line is supported ([Concepts](concepts.md#where-php-runs)). |
| Containers | Not tried ([Installation](installation.md#offline-and-containers)). |
| X11 | The platform of every test and example, on a virtual X server. |
| Wayland | Not run. With `XDG_SESSION_TYPE=wayland` and no compositor, a program without the `ozone-platform=x11` switch failed in `Cef::initialize()` ([Wayland and GPU](wayland-gpu.md#x11-or-wayland)). |
| GPU | The tests run with `disable-gpu`. WebGL is off in that setting and on a virtual X server without the switch; nothing was run against a physical GPU ([Wayland and GPU](wayland-gpu.md#gpu-on-and-off)). |
| Desktop window managers | Not tried (GNOME, KDE, Sway). Input tests ran with no window manager and with `twm`. Maximizing and minimizing a window need a window manager and are not tested. |
| Distribution | The package is not published: no release exists and the package is not on Packagist. A `pie install` from a release was never run; the steps were tried offline ([Installation](installation.md), [Development](development.md#packaging-and-release)). |
| Licence | The repository has no licence file, and the licence notices that redistributing the CEF runtime requires were not collected. |

## Process and runtime

| Item | Status |
| --- | --- |
| CEF starts once per process | After `Cef::shutdown()` it cannot be initialized again. A program that needs CEF again starts another PHP process ([Concepts](concepts.md#processes)). |
| Sandbox | Off (`no_sandbox`). A run with the sandbox on was not tried ([Configuration](configuration.md#the-sandbox)). |
| PHP runs on the UI thread only | A handler that blocks freezes every window and page. A callback that CEF makes on another thread and that needs an answer at once waits for PHP at most 5 seconds, then CEF's default is used (`CEFLOOM_SYNC_WAIT_MS`; [Handlers](handlers.md#callbacks-on-other-threads)). |
| Process that does not exit | Rarely the PHP process stayed alive after the program printed its last line. Seen a few times, cause not found ([Troubleshooting](troubleshooting.md#known-problems)). |
| Long runs | Not tried: tests run a few seconds each; a long-running program, many browsers over time and memory growth were not measured. |
| One profile per process | Two processes cannot share one `rootCachePath` ([Troubleshooting](troubleshooting.md#behaviour)). |
| `FILE_BACKGROUND` tasks | Chromium holds back work posted to `ThreadId::FILE_BACKGROUND` until a browser has started ([Concepts](concepts.md#threads-and-tasks)). |

## Features

| Item | Status |
| --- | --- |
| Cast | After `addObserver()` or `notifyCurrentSinks()`, `Cef::shutdown()` ends the process with a trap. Cause inside CEF, not solved. No Cast device was available: device lists and sessions were not exercised ([Cast](cast.md)). |
| `BrowserHost::print()` | In the default (Chrome) runtime style it opens Chrome's print preview and does not call a `PrintHandler`. The handler is called in the Alloy style (`runtimeStyle = 2`), and without a printer needs `disable-features=EnableOopPrintDrivers`. A real printer and the print dialog were not tried ([Printing and PDF](printing.md)). |
| `AudioHandler` | Asked only by Alloy-style browsers and only for a page that makes sound. Without a sound device it needs `disable-audio-output`. Playing to a real sound device was not tried ([Handlers](handlers.md)). |
| `LifeSpanHandler::doClose()` | Not called for browsers made with `BrowserHost::createBrowser()`. Closing goes straight to `onBeforeClose()`, and `doClose()` returning `true` does not stop it. |
| Views | A `BrowserView` with a live browser cannot be removed from its window: `removeChildView()` and `removeAllChildViews()` throw an `Error` ([Windows with Views](views.md#input)). Only Linux and X11 were tried. |
| `onResourceRedirect` | The URL it returns is not applied: the load ends at the URL the server gave. Probable cause: the CEF wrapper copies the argument and does not write it back (not confirmed). Workaround: `Request::setURL()` in `onBeforeResourceLoad`. `tests/t_redirect_change.php` records the failure and will report when it is fixed. |
| `RequestContextHandler::getResourceRequestHandler()` | Synchronous on the IO thread; during the first navigation of a new context it waited for the whole limit (5 seconds by default) on every request, then CEF's default was used. Use `RequestHandler::getResourceRequestHandler()` of the browser, or lower `CEFLOOM_SYNC_WAIT_MS`. |
| `Server::isRunning()` | Always `false` when called from PHP, because CEF answers it on the server thread only. Use `ServerHandler::onServerCreated()` and `onServerDestroyed()`. |
| Calls CEF refuses on the UI thread | `Cef::directoryExists()`, seven file functions, `WaitableEvent::wait()` and `timedWait()` return `false` on the UI and IO threads in CEF; the binding runs them on another thread. |
| `Thread::create(..., stoppable: false, ...)` | Releasing such a thread (or `Cef::shutdown()` releasing it) ends the process with a trap. The tests use `stoppable: true`. |
| Observer registrations | `addPreferenceObserver()` and `addSettingObserver()` stop observing when the returned `Registration` is released: keep it in a variable. |
| `null` completion callbacks | `RequestContext::clearHttpCache(null)`, `clearHttpAuthCredentials(null)` and `closeAllConnections(null)` crash in CEF itself, although its header says `NULL` is allowed. The binding passes a callback that does nothing instead. |
| `ResourceBundle` ids | An id that is not in the pack file ends the process (`getLocalizedString()`, `getDataResource()`). Use ids from `cef_pack_strings.h` and `cef_pack_resources.h`. |
| `XmlReader::getAttribute(string)` | Only the index overloads exist; of overloads with the same name, the generator makes the first. |

## Media

Run in the probe of [Wayland and GPU](wayland-gpu.md#gpu-on-and-off) with `canPlayType()` on a `<video>` and an `<audio>` element (`ozone-platform=x11`, `disable-gpu`, `xvfb-run`):

| Type | `canPlayType()` |
| --- | --- |
| `video/webm; codecs="vp9"` | `probably` |
| `video/mp4; codecs="av01.0.05M.08"` (AV1) | `probably` |
| `video/mp4; codecs="avc1.42E01E"` (H.264) | empty: not supported |
| `audio/webm; codecs="opus"` | `probably` |
| `audio/mpeg` (MP3) | `probably` |
| `audio/mp4; codecs="mp4a.40.2"` (AAC) | empty: not supported |

This is one run of the runtime that `cefloom-runtime` installs. Playing actual files, the microphone and the camera were not tried. A runtime built with proprietary codecs was not tried.

## JavaScript bridge and API

* **Every page can call every exposed function.** `Cef::expose()` is not per page or per origin ([JavaScript bridge](javascript-bridge.md)).
* **`executeJavaScript()` returns nothing.** A result comes back through `cefloom.call()` or `console.log` ([JavaScript bridge](javascript-bridge.md#php-calls-javascript)).
* **Bridge arguments and results are JSON.** A result that cannot be encoded rejects the promise.
* **Handlers are called as long as the loop runs.** Nothing is delivered between calls of `Cef::doMessageLoopWork()` ([The message loop](message-loop.md)).
* **The external pump** (`externalMessagePump = 1`) and polling both pass the tests, including input. The idle CPU figures on that page come from one measurement.

## Scope of the tests

* Every test runs in its own PHP process under `xvfb-run`, in polling mode, and the suite is also run with `CEFLOOM_TEST_EXTERNAL_PUMP=1` ([Development](development.md#tests)).
* Input tests send events with `xdotool` to a window without a window manager and to one under `twm`.
* Network tests use a local HTTP server and a local TLS server; nothing was tried against the internet.
* Not run at all: a real Wayland session, a physical GPU, a desktop environment's window manager, a real Cast device, a real printer and print dialog, a sound device, other PHP builds (8.3 and 8.4, thread-safe), arm64, other distributions, containers, Windows and macOS.
