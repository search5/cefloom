# Installation

cefloom comes in three parts that are installed separately, because they are different in size and in how they travel:

| Part | What it is | Size | Installed by |
| --- | --- | --- | --- |
| The extension | `cefloom.so`, the PHP side | about 4 MB | [PIE](https://github.com/php/pie) |
| The CEF runtime | Chromium: `libcef.so`, resources, `cefloomsubprocess` | about 150 MB compressed, 360 MB unpacked | `cefloom-runtime` |
| The stub | `php/cefloom.stub.php`, declarations for an IDE | small | copy it, or point the IDE at it |

> **Status.** The package is `search5/cefloom` (source: <https://github.com/search5/cefloom>), but no release is published and the package is not on Packagist yet. The commands below show how it will work; until then, [build from source](development.md#building-from-source).

## Requirements

* Linux x86_64 with glibc. It was built and tested on Ubuntu only; other glibc distributions were not tried. CEF is built for glibc, so Alpine (musl) is not supported. arm64 is built the same way but was not tried.
* PHP 8.5 CLI, non-thread-safe (NTS), with the `phar` and `zlib` extensions (a default CLI has both). PHP-FPM and thread-safe PHP were not tried; see [Concepts](concepts.md#where-php-runs).
* A display for windows: an X server (X11), or `xvfb-run -a` on a machine without one. Wayland sessions were not tried; CEF is started with `ozone-platform=x11` in the examples.
* The system libraries that Chromium loads (GTK 3, NSS, ALSA, and so on). A desktop installation has them; on a minimal server or in a container, `ldd ~/.local/share/cefloom/runtime/*/libcef.so | grep 'not found'` lists what is missing. The package names were not checked for any distribution.

## 1. The extension

Install PIE once (see the PIE documentation), then:

```sh
pie install search5/cefloom
```

PIE downloads the file `php_cefloom-<version>_php<X.Y>-x86_64-linux-glibc-nts.zip` that is attached to the release of the package, puts `cefloom.so` into PHP's extension directory and enables it in `php.ini`. cefloom does not need compiler tools for this: the extension is not built on your machine.

The extension **loads without the CEF runtime** and costs a PHP process that does not use CEF about 4 MB of memory and a few milliseconds at start (measured: 20.5 MB against 16 MB for PHP without the extension), so it can stay enabled in `php.ini` for every script. Calling CEF without the runtime throws an `Error` that says so.

Check it:

```sh
php --ri cefloom
```

## 2. The CEF runtime

```sh
php bin/cefloom-runtime install --base-url=https://github.com/search5/cefloom/releases/download/<version>
php bin/cefloom-runtime status
```

`install` downloads `cefloom-runtime-<CEF version>-linux-x86_64.tar.gz` and its `.sha256`, checks the checksum and that the archive holds the CEF version this extension was built against, and unpacks it into

```
$XDG_DATA_HOME/cefloom/runtime/<CEF version>      (default: ~/.local/share/cefloom/runtime/154.0.34)
```

No administrator rights are needed. The extension looks for the runtime in this order: the directory named by `CEFLOOM_HOME`; the directory of `cefloom.so`, if `libcef.so` is there (the layout of a build directory); the user directory above. `cefloom-runtime path` prints the one in use, `status` tells whether it is there and which CEF version it holds.

Other ways to say where the runtime is:

```sh
php bin/cefloom-runtime install --url=/path/to/cefloom-runtime-154.0.34-linux-x86_64.tar.gz --sha256=<hex>
php bin/cefloom-runtime install --dir=/opt/cefloom-runtime --force      # another place; then set CEFLOOM_HOME to it
CEFLOOM_RUNTIME_BASE_URL=... php bin/cefloom-runtime install            # the same, from the environment
```

Without `--sha256` the tool reads `<archive>.sha256` next to the archive; it refuses to install without a checksum.

## Offline and containers

Download `php_cefloom-…zip` and `cefloom-runtime-…tar.gz` (with the `.sha256` files) from the release page on a machine with network access, then on the target:

```sh
unzip php_cefloom-*.zip -d "$(php -r 'echo ini_get("extension_dir");')"
echo extension=cefloom.so > /etc/php/8.5/cli/conf.d/20-cefloom.ini      # on Debian and Ubuntu; else add the line to php.ini
php bin/cefloom-runtime install --url=cefloom-runtime-154.0.34-linux-x86_64.tar.gz
```

In a container (not tried), install the runtime while building the image, for example with `--dir=/opt/cefloom`, and set `CEFLOOM_HOME=/opt/cefloom`. The container needs the libraries Chromium needs (see Requirements) and, for windows, a display. `cefloom` starts CEF with the sandbox off (`no_sandbox`), because the sandbox needs `chrome-sandbox` set up as root; see [Configuration](configuration.md#the-sandbox).

## Update and remove

```sh
pie upgrade                 # the extension
php bin/cefloom-runtime install --force --base-url=...    # the runtime, when the CEF version changed
pie uninstall search5/cefloom
rm -rf ~/.local/share/cefloom                            # the runtime
```

The extension and the runtime must be the same CEF version: `php -r 'echo Cefloom\Cef::cefVersion();'` says which one the extension wants. A runtime of another version is not used, and `cefloom-runtime install` refuses it.
