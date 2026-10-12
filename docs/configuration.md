# Configuration

## Settings

`Cef::initialize(?Settings $settings = null, ?App $app = null)` takes CEF's settings. The ones you will want:

| Property | Meaning |
| --- | --- |
| `rootCachePath` | where CEF keeps cookies, cache and local storage. Default: `$XDG_CACHE_HOME/cefloom` (`~/.cache/cefloom`). Two processes cannot use the same one at the same time |
| `cachePath` | the profile directory inside the root cache that keeps cookies and storage; as in CEF, empty means nothing is saved (in-memory) |
| `userAgent`, `userAgentProduct`, `acceptLanguageList`, `locale` | what the browser says about itself |
| `logFile`, `logSeverity` | where CEF writes its log and how much; raise the severity to quiet it |
| `remoteDebuggingPort` | open DevTools for the pages at `http://localhost:<port>` |
| `externalMessagePump` | event-driven loop; off by default (polling is the simplest; both modes handle input in windows made by CEF) ([The message loop](message-loop.md#polling-and-the-external-pump)) |
| `windowlessRenderingEnabled` | off-screen rendering |
| `backgroundColor`, `javascriptFlags`, `persistSessionCookies` | as in CEF |

cefloom fills in what makes it work: the path of `cefloomsubprocess`, the resource and locale directories (all from the [runtime directory](installation.md#2-the-cef-runtime)), the cache directory, and `no_sandbox`. Set a path yourself and cefloom leaves it alone. The full list of properties is in `php/cefloom.stub.php`.

## Command line switches

```php
Cef::addSwitch('ozone-platform', 'x11');       // before initialize()
Cef::addSwitch('disable-gpu');
```

Any Chromium switch works. Useful ones: `ozone-platform=x11` (force X11 inside a Wayland session), `disable-gpu` (software rendering, for servers and virtual displays), `lang=...`.

## Custom schemes

`Cef::registerCustomScheme($name, $options)` before `initialize()`; see [Handlers](handlers.md#custom-schemes-and-resources).

## Environment variables

| Variable | Effect |
| --- | --- |
| `CEFLOOM_HOME` | the directory of the CEF runtime. Checked first |
| `XDG_DATA_HOME` | where `cefloom-runtime` installs the runtime and where the extension looks last (`$XDG_DATA_HOME/cefloom/runtime/<CEF version>`) |
| `XDG_CACHE_HOME` | the default root cache path |
| `CEFLOOM_SYNC_WAIT_MS` | how long a CEF thread waits for a stuck PHP thread in a [synchronous callback](handlers.md#callbacks-on-other-threads) (default 5000) |
| `CEFLOOM_RUNTIME_URL`, `CEFLOOM_RUNTIME_BASE_URL` | where `cefloom-runtime install` downloads from |

## Functions that tell where things are

```php
Cefloom\Cef::cefVersion();             // the CEF version this extension was built against
Cefloom\Cef::runtimeDirectory();       // the directory the runtime is taken from now
Cefloom\Cef::userRuntimeDirectory();   // where cefloom-runtime installs it
```

## The sandbox

CEF's sandbox needs `chrome-sandbox` to be installed set-uid root, which a PHP program cannot arrange. cefloom starts CEF with the sandbox **off** (`no_sandbox`). The renderer processes are then not confined by Chromium's sandbox: load only content you trust, as you would with any program that embeds a browser without the sandbox.

## Files

| Path | What |
| --- | --- |
| `~/.local/share/cefloom/runtime/<CEF version>/` | the runtime installed for the user |
| `~/.cache/cefloom/` | the root cache path (profile data) |
| `<extension_dir>/cefloom.so` | the extension |
