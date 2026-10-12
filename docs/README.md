# cefloom documentation

cefloom puts the Chromium Embedded Framework (CEF) into PHP: PHP opens the windows, loads the pages, answers their events and is called from their JavaScript.

| | |
| --- | --- |
| [Installation](installation.md) | Install the extension with PIE and the CEF runtime with `cefloom-runtime`; update, remove, offline installs |
| [Getting started](getting-started.md) | A first program, step by step |
| [Concepts](concepts.md) | The PHP thread, handlers and delegates, return values, lifetime, errors |
| [The message loop](message-loop.md) | `Cef::run()`, your own loop, polling and the external pump |
| [Handlers](handlers.md) | Events, requests, custom schemes and resources, tasks |
| [JavaScript bridge](javascript-bridge.md) | `Cef::expose()` and `cefloom.call()` |
| [Windows with Views](views.md) | CEF's own windows, buttons and layout |
| [Printing and PDF](printing.md) | `printToPDF()` |
| [Cast](cast.md) | Media sources and devices |
| [Configuration](configuration.md) | Settings, switches, environment variables, files |
| [Troubleshooting](troubleshooting.md) | Messages and what to do, known problems |
| [Development](development.md) | Build, test, package and release |

The runnable programs are in [`../examples/`](../examples/); `php/cefloom.stub.php` declares every class for an IDE.

**Status.** Linux x86_64 with glibc and PHP 8.5 (NTS, CLI) is the only platform that has been built and tested. The package is `search5/cefloom` and the source is at <https://github.com/search5/cefloom>, but no release has been published and the package is not on Packagist yet, so `pie install` does not work yet; [Development](development.md#building-from-source) describes how to build until then. Where something was not tried, the page says so.
