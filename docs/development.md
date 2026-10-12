# Development

## Building from source

You need: PHP 8.5 with its development files (`phpize`), a C++20 compiler, `rsync`, `patchelf`, `python3`, and a **CEF distribution**: the headers, the wrapper library `libcef_dll_wrapper.a` built from them, and the runtime files (`libcef.so`, the `.pak` files, `icudtl.dat`, `locales/`, and so on). CEF 154.0.34 is the version this was built against.

```sh
tools/build.sh                   # build/dist: cefloom.so, cefloomsubprocess and the runtime files
tools/build.sh --regen           # regenerate the binding from the CEF headers first (python3, tools/gen)
```

`tools/build.sh` reads its inputs from environment variables whose defaults are the layout of the workspace this repository grew in; set them for yours:

| Variable | Meaning |
| --- | --- |
| `CEF_ROOT` | the CEF distribution (the directory with `include/`) |
| `CEFLOOM_WRAPPER_LIB` | `libcef_dll_wrapper.a` built from that distribution |
| `CEFLOOM_RUNTIME` | a directory with `libcef.so`, the `.pak` files, `icudtl.dat`, `locales/` |
| `BUILD` | the build directory (default `build/`) |
| `CEFLOOM_LAZY_LIBCEF=0` | link `libcef.so` instead of loading it when needed (the default is to load it when needed) |
| `JOBS` | parallel compile jobs |

How to prepare a CEF distribution and its wrapper library is not automated here yet.

The extension is built **without** a link to `libcef.so`, so that it loads where the runtime is not installed ([Installation](installation.md#1-the-extension)). A compile error stops the build; the build directory is rebuilt from scratch when `CEFLOOM_LAZY_LIBCEF` changes.

## Tests

```sh
tests/run.sh                    # every test, one after another
tests/run.sh t_views t_pdf      # some
CEFLOOM_TEST_EXTERNAL_PUMP=1 tests/run.sh      # the event-driven loop (the input test is skipped)
CEFLOOM_DIST=/path/to/dist tests/run.sh        # another build directory
```

Each test is a PHP script that runs in its own process (CEF starts once per process) under `xvfb-run`, and prints `OK (n checks)`. The runner puts each test in its own session, stops what is left of it, and fails a test that takes too long (`TEST_TIMEOUT`, default 120 s). **Run tests one at a time** (the runner does). Several CEF test processes at once were avoided because they were suspected of making tests hang; that was not confirmed. `xvfb-run` and `xdotool` are needed (`t_views_input` skips itself without `xdotool`).

`tests/t_examples.php` runs the programs in `examples/`.

## Packaging and release

```sh
tools/package.sh                # build/release/
```

writes, with a `.sha256` next to each:

| File | For |
| --- | --- |
| `php_cefloom-<version>_php<X.Y>-<arch>-linux-glibc-nts.zip` | PIE; holds `cefloom.so` at its top |
| `cefloom-runtime-<CEF version>-linux-<arch>.tar.gz` | `cefloom-runtime install` |

`<version>` is `CEFLOOM_VERSION` in `ext/src/cefloom.cc`. The first file's name follows PIE's rule (read from PIE 1.5.2: lower case, `php_<extension>-<version>_php<major.minor>-<arch>-<os>-<libc>[-debug](-nts|-zts)`, `.zip` or `.tgz`). `tools/package.sh` refuses a module that links `libcef.so`, a thread-safe or debug PHP, and a system without glibc.

To publish (not done yet):

1. The repository is on GitHub (`search5/cefloom`) and `composer.json` names the package `search5/cefloom`. Choose a licence and add it (there is none yet).
2. Tag the version (`git tag <version>`; keep the tag and `CEFLOOM_VERSION` equal, and use a tag without a leading `v`: whether PIE strips it when it builds the asset name was not checked) and create a GitHub release with both files and their checksums as assets. PIE finds the prebuilt binary among the assets of the release **by tag name**.
3. Submit the repository to [Packagist](https://packagist.org). Then `pie install <name>` works.
4. Build the files for each PHP version you support (8.5 so far), on the platform each is for.
5. Tell users where the runtime archive is: `cefloom-runtime install --base-url=<the release's download directory>`.

Not yet checked: a real `pie install` from a published release (everything before it was tried offline and with PIE's metadata checks), and the licence notices that redistributing the CEF runtime requires.

## Where the design is written down

`llm-wiki/` holds the architecture, the decisions (with the reasons), the experiments and what each established, and the list of what is not known. `llm-wiki/index.md` is the way in.
