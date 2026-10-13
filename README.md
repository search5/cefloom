# cefloom

Table of contents:
* [Introduction](#introduction)
* [Documentation](#documentation)
* [Install](#install)
* [Supported platforms](#supported-platforms)
* [Examples](#examples)
* [Build and test](#build-and-test)
* [Related projects](#related-projects)
* [License](#license)


## Introduction

cefloom is a PHP extension that binds the [Chromium Embedded Framework](https://bitbucket.org/chromiumembedded/cef) (CEF). PHP opens the windows, loads the pages, answers their events in PHP classes and is called from their JavaScript.

```php
Cef::initialize();
Cef::expose('add', fn(int $a, int $b): int => $a + $b);   // in the page: await cefloom.call('add', 2, 3)
BrowserHost::createBrowser($info, new App(), 'https://example.com', new BrowserSettings(), null, null);
Cef::run();
```

* The CEF API is generated from the CEF headers (`tools/gen`); the descriptions in the reference are the comments of those headers.
* The PHP main thread is the CEF UI thread, so a handler is plain PHP code and runs inside `Cef::run()` or `Cef::doMessageLoopWork()`.
* Pages can be served by PHP (custom schemes and resource handlers), PHP functions can be exposed to JavaScript (`Cef::expose`, `cefloom.call`), and a page can be saved as a PDF (`printToPDF`).
* A browser window is either one you describe with `WindowInfo` or one drawn by CEF's Views framework (buttons, layouts, menus).

Only the command line (CLI) is supported. This is an early project: no release has been published yet.


## Documentation

The guide, the cookbook and the API reference are published on GitHub Pages, in English and Korean:

* English: <https://search5.github.io/cefloom/en/index.html>
* Korean: <https://search5.github.io/cefloom/ko/index.html>

The same pages are in [`docs/`](docs/README.md): installation, getting started, concepts, the message loop, handlers, the JavaScript bridge, Views, context menu, sound and camera, printing, Cast, configuration, Wayland and GPU, troubleshooting, limitations and development. The API reference is written from [`php/cefloom.stub.php`](php/cefloom.stub.php), which also declares every class for an IDE. `make -C docs site` builds both languages.


## Install

Three parts, because they travel differently:

1. **The extension** (`cefloom.so`, 4 MB) is installed with [PIE](https://github.com/php/pie), the PHP extension installer:

   ```sh
   pie install search5/cefloom
   ```

   PIE downloads the prebuilt `php_cefloom-<version>_php<X.Y>-<arch>-linux-glibc-nts.zip` that is attached to the release. **No release is published and the package is not on Packagist yet, so this does not work yet**; [build from source](#build-and-test) until then. The extension loads without the CEF runtime and costs a PHP process that does not use CEF almost nothing (about 4 MB more memory).

2. **The CEF runtime** (Chromium, about 150 MB compressed) is installed by the tool in `bin/`:

   ```sh
   php bin/cefloom-runtime install --base-url=<the release directory>
   php bin/cefloom-runtime status
   ```

   It goes to `~/.local/share/cefloom/runtime/<CEF version>` (no administrator rights). The extension looks, in this order, in `CEFLOOM_HOME`, next to `cefloom.so`, and there. Without a runtime, `Cef::initialize()` throws an error that says so.

3. **Autocompletion**: `php/cefloom.stub.php` declares every class for an IDE.

[Installation](docs/installation.md) has the details: offline installs, containers, update and remove.


## Supported platforms

OS | PHP | Architecture | Status
--- | --- | --- | ---
Linux (glibc) | 8.5 CLI, NTS | x86_64 | Built and tested (Ubuntu only)
Linux (glibc) | 8.5 CLI, NTS | arm64 | Built the same way, not tried
Linux (musl, Alpine) | | | Not supported (CEF is built for glibc)
macOS, Windows | | | Not tried

PHP-FPM and thread-safe PHP were not tried. Browser windows on screen need an X server (X11) or `xvfb-run -a`; Wayland sessions were not tried. [Limitations](docs/limitations.md) lists what is not covered.


## Examples

Run them with `php -d extension=build/dist/cefloom.so examples/<name>.php` (add `xvfb-run -a` on a machine without a display).

| Example | What it shows |
| --- | --- |
| `hello.php` | The smallest program: a window, a page, JavaScript calling a PHP function (`Cef::expose`, `cefloom.call`). |
| `views.php` | A window drawn by CEF's Views framework: a toolbar with Back, Forward and Reload buttons above the page, layout, delegates. `--close-after=SECONDS` closes it by itself. |
| `print-pdf.php` | Saves a web page as a PDF: `print-pdf.php <url> <output.pdf> [--landscape] [--letter]`. |
| `cast.php` | Cast and DIAL media sources, and with `--discover` the devices the media router finds. Ending a program after `--discover` hits a known CEF problem (see `llm-wiki`, K26). |

`tests/t_examples.php` runs them, so they keep working. The recipes of the [cookbook](docs/cookbook.md) are in [`docs/recipes/`](docs/recipes/).


## Build and test

```sh
tools/build.sh            # build/dist: cefloom.so, cefloomsubprocess and the CEF runtime files
tools/package.sh          # build/release: the PIE archive and the runtime archive, with checksums
tests/run.sh              # the tests, one after another, under a virtual X server
```

`tools/build.sh` needs a CEF distribution, its wrapper library and a runtime directory (see the variables at the top of the script). There are 61 test files in `tests/`, each in its own PHP process. Documentation of the design, the findings and the decisions is in `llm-wiki/`.


## Related projects

* [cefweaver](https://github.com/search5/cefweaver): the same CEF binding for Python, with documentation at <https://search5.github.io/cefweaver/>. Its guide covers the same topics as this one.
* [cefpython](https://github.com/cztomczak/cefpython): an earlier CEF binding for Python.


## License

The repository has no license file yet; the owner has not chosen one (`llm-wiki`, K28).
