# Wayland and GPU

On Linux Chromium draws and receives input through a platform layer called ozone, and the `ozone-platform` switch chooses it (`x11`, `wayland`, `headless`). This page lists the combinations of display, platform and GPU switches that were run with cefloom, what each did, and what was not run. The switches are given with `Cef::addSwitch()` before `Cef::initialize()` ([Configuration](configuration.md#command-line-switches)).

**cefloom was run on a virtual X server (`xvfb-run`) only.** No test, example or recipe was run on a real Wayland session or against a physical GPU. Everything below that is about Wayland is either what Chromium did when no Wayland compositor was there, or what the existing pages say and was not checked further.

## What the tests and examples use

All of them add the same two switches before `initialize()`:

```php
Cef::addSwitch('ozone-platform', 'x11');
Cef::addSwitch('disable-gpu');
```

`examples/hello.php`, `examples/views.php` and `examples/print-pdf.php` set `ozone-platform=x11`; `tests/support.php` (`start_cef()`, used by every test) sets both, and `docs/recipes/cookbook.php` sets both. `tests/run.sh` starts each test under `env -u WAYLAND_DISPLAY xvfb-run -a`, so a test never finds a Wayland socket through `WAYLAND_DISPLAY`. The suite therefore runs one combination: X11 on a virtual screen, GPU off.

## X11 or Wayland

On a Wayland desktop with no `ozone-platform` switch, Chromium may choose Wayland. This was run under a virtual X server with an empty `XDG_RUNTIME_DIR` (so that no Wayland socket existed) and `WAYLAND_DISPLAY` unset. The rows that failed were run three times; the others at least once:

| `XDG_SESSION_TYPE` | Switch | Result |
| --- | --- | --- |
| `wayland` | none | `Cef::initialize()` returned `false`. Standard error: `Failed to connect to Wayland display` (followed by the system's message for a missing file), `Failed to initialize Wayland platform`, `The platform failed to initialize.  Exiting.` |
| `wayland` | `ozone-platform=wayland` | the same |
| `wayland` | `ozone-platform-hint=x11` | `initialize()` returned `false`, the same Wayland messages (one run) |
| `wayland` | `ozone-platform=x11` | worked |
| `x11` or unset | none | worked |

So with `XDG_SESSION_TYPE=wayland` in the environment, the choice of Chromium followed it even though `WAYLAND_DISPLAY` was unset. What a program without the switch does on a real Wayland session, where a compositor answers, was not run. To stay on X11 (XWayland on a Wayland desktop) give the switch, which is what the examples do:

```php
Cef::addSwitch('ozone-platform', 'x11');
```

[Troubleshooting](troubleshooting.md#messages) lists the same switch for `Missing X server or $DISPLAY`.

`ozone-platform=x11` needs a reachable X server: with `DISPLAY` and `WAYLAND_DISPLAY` unset it failed with `Missing X server or $DISPLAY` and `initialize()` returned `false`.

## GPU on and off

`Cef::addSwitch('disable-gpu')` is what the tests use. What the switch changes was measured with `docs/recipes/gpu-probe.php`, which starts CEF with the switches in `PROBE_SWITCHES`, loads a page served by PHP that asks for a WebGL and a WebGL 2 context, and reports what it got. Each line was run three times under `xvfb-run` with `ozone-platform=x11` (except where noted):

```sh
PROBE_SWITCHES=ozone-platform=x11,disable-gpu xvfb-run -a php -d extension=build/dist/cefloom.so docs/recipes/gpu-probe.php
```

| Switches | WebGL and WebGL 2 in the page |
| --- | --- |
| `disable-gpu` | none (no context) |
| none | none; standard error: `WebGL1 blocklisted`, `WebGL2 blocklisted` |
| `disable-gpu`, `enable-unsafe-swiftshader` | a context; the renderer string is `ANGLE (Google, Vulkan 1.3.0 (SwiftShader Device (Subzero) (0x0000C0DE)), SwiftShader driver)` |
| `use-gl=angle`, `use-angle=swiftshader` | the same SwiftShader context (one run) |

* The virtual X server has no GPU, so the second line says what Chromium did on that screen, not what it does on a machine with a GPU. A run on a real X11 screen with a GPU was not made.
* With `disable-gpu` a process of type `gpu-process` was still running (in every run above): the switch turns off hardware acceleration, not the GPU process.
* The runtime directory (`build/dist/`) holds `libvk_swiftshader.so` and `vk_swiftshader_icd.json` next to `libcef.so`.
* WebGL is therefore off in the test suite and in the examples. A program that needs WebGL on a machine without a GPU can add `enable-unsafe-swiftshader` as in the third line (software rendering, slow; its speed was not measured).

Video and sound were not tried. Whether a given video plays with the GPU on or off was not checked on any machine. What the page reports for codecs is under [Limitations](limitations.md#media).

### A page that draws by itself

Off-screen rendering (`windowlessRenderingEnabled`) was run with `disable-gpu` and `ozone-platform=x11` only (`tests/t_osr.php`, which reads the pixels). Off-screen with the GPU on was not tried.

## Without a screen

Two ways were run.

* **`xvfb-run -a php ...`**: a virtual X server. The windows, the Views windows, the input from `xdotool` and the tests all work on it ([Views](views.md#without-a-display), `tests/t_views_input.php`). Run it with `env -u WAYLAND_DISPLAY` and, if the session is Wayland, with the `ozone-platform=x11` switch, so that nothing reaches the real compositor.
* **`ozone-platform=headless`** with `DISPLAY` and `WAYLAND_DISPLAY` unset: `initialize()` returned `true`, and a browser created with `BrowserHost::createBrowser()` and a `WindowInfo` loaded the page and ran its script (the probe got its answer from `cefloom.call`), with `disable-gpu` (three runs) and without (one run). Only this one kind of window was tried; Views windows and off-screen rendering on the headless platform were not.

The switch `headless` (without `ozone-platform`) was tried once with `DISPLAY` unset: `initialize()` returned `true`, but the GPU process exited with `Exiting GPU process due to errors during initialization` (and ANGLE could not open the default X display), and the page's answer never arrived. It was not looked into further.

## Not tried

* Any run on a real Wayland session: windows opened by CEF on a compositor, input, closing a window from code, `ozone-platform=wayland` with a compositor.
* A desktop environment's window manager (GNOME, KDE, Sway); only no window manager and `twm` were used for input tests ([Views](views.md#input)).
* A GPU, with X11 or with Wayland: video playback, WebGL, the GPU process on a real screen, and the effect of other GPU switches.
* A toolkit using Wayland natively beside CEF (cefloom has no toolkit adapters).
