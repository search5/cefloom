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
| [Context menu](context-menu.md) | `ContextMenuHandler`, `ContextMenuParams`, `MenuModel`: read, change and answer the right-click menu |
| [Sound, microphone and camera](media.md) | `AudioHandler`, muting, and the permission to use a microphone or camera |
| [Cast](cast.md) | Media sources and devices |
| [Configuration](configuration.md) | Settings, switches, environment variables, files |
| [Wayland and GPU](wayland-gpu.md) | Which display and GPU setups were tried, and the switches they need |
| [Troubleshooting](troubleshooting.md) | Messages and what to do, known problems |
| [Cookbook](cookbook.md) | Short recipes: a page served by PHP, PHP and JavaScript calling each other, a PDF |
| [Limitations](limitations.md) | Platforms, features and test scope that are not covered |
| [Development](development.md) | Build, test, package, release and build these pages |

The runnable programs are in [`../examples/`](../examples/); `php/cefloom.stub.php` declares every class for an IDE.

**Status.** Linux x86_64 with glibc and PHP 8.5 (NTS, CLI) is the only platform that has been built and tested. The package is `search5/cefloom` and the source is at <https://github.com/search5/cefloom>, but no release has been published and the package is not on Packagist yet, so `pie install` does not work yet; [Development](development.md#building-from-source) describes how to build until then. Where something was not tried, the page says so.
