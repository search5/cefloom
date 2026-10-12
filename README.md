# cefloom

The Chromium Embedded Framework for PHP: open windows and web pages from PHP, handle their events in PHP classes, and call PHP functions from JavaScript.

```php
Cef::initialize();
Cef::expose('add', fn(int $a, int $b): int => $a + $b);   // in the page: await cefloom.call('add', 2, 3)
BrowserHost::createBrowser($info, new App(), 'https://example.com', new BrowserSettings(), null, null);
Cef::run();
```

Linux x86_64 with glibc and PHP 8.5 (NTS) is the only platform that has been tested.

## Examples

Run them with `php -d extension=build/dist/cefloom.so examples/<name>.php` (add `xvfb-run -a` on a machine without a display).

| Example | What it shows |
| --- | --- |
| `hello.php` | The smallest program: a window, a page, JavaScript calling a PHP function (`Cef::expose`, `cefloom.call`). |
| `views.php` | A window drawn by CEF's Views framework: a toolbar with Back, Forward and Reload buttons above the page, layout, delegates. `--close-after=SECONDS` closes it by itself. |
| `print-pdf.php` | Saves a web page as a PDF: `print-pdf.php <url> <output.pdf> [--landscape] [--letter]`. |
| `cast.php` | Cast and DIAL media sources, and with `--discover` the devices the media router finds. Ending a program after `--discover` hits a known CEF problem (see `llm-wiki`, K26). |

`tests/t_examples.php` runs them, so they keep working.

## Documentation

[`docs/`](docs/README.md): installation (PIE and the runtime tool), getting started, concepts, the message loop, handlers, the JavaScript bridge, Views, printing, Cast, configuration, troubleshooting and development.

## Install

Three parts, because they travel differently:

1. **The extension** (`cefloom.so`, 4 MB) is installed with [PIE](https://github.com/php/pie), the PHP extension installer:

   ```sh
   pie install search5/cefloom
   ```

   PIE downloads the prebuilt `php_cefloom-<version>_php<X.Y>-<arch>-linux-glibc-nts.zip` that is attached to the release. The extension loads without the CEF runtime and costs a PHP process that does not use CEF almost nothing (about 4 MB more memory).

2. **The CEF runtime** (Chromium, about 150 MB compressed) is installed by the tool in `bin/`:

   ```sh
   php bin/cefloom-runtime install --base-url=<the release directory>
   php bin/cefloom-runtime status
   ```

   It goes to `~/.local/share/cefloom/runtime/<CEF version>` (no administrator rights). The extension looks, in this order, in `CEFLOOM_HOME`, next to `cefloom.so`, and there. Without a runtime, `Cef::initialize()` throws an error that says so.

3. **Autocompletion**: `php/cefloom.stub.php` declares every class for an IDE.

## Build

```sh
tools/build.sh            # build/dist: cefloom.so, cefloomsubprocess and the CEF runtime files
tools/package.sh          # build/release: the PIE archive and the runtime archive, with checksums
tests/run.sh              # the tests, one after another, under a virtual X server
```

`tools/build.sh` needs a CEF distribution, its wrapper library and a runtime directory (see the variables at the top of the script). Documentation of the design, the findings and the decisions is in `llm-wiki/`.
