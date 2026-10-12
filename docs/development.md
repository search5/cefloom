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
| `CEFLOOM_COVERAGE=1` | a build that counts executed functions (see Coverage); slow, not for use |
| `JOBS` | parallel compile jobs |

How to prepare a CEF distribution and its wrapper library is not automated here yet.

The extension is built **without** a link to `libcef.so`, so that it loads where the runtime is not installed ([Installation](installation.md#1-the-extension)). A compile error stops the build; the build directory is rebuilt from scratch when `CEFLOOM_LAZY_LIBCEF` changes.

## Tests

```sh
tests/run.sh                    # every test, one after another
tests/run.sh t_views t_pdf      # some
CEFLOOM_TEST_EXTERNAL_PUMP=1 tests/run.sh      # the event-driven loop (the same tests, input included)
CEFLOOM_DIST=/path/to/dist tests/run.sh        # another build directory
```

Each test is a PHP script that runs in its own process (CEF starts once per process) under `xvfb-run`, and prints `OK (n checks)`. The runner puts each test in its own session, stops what is left of it, and fails a test that takes too long (`TEST_TIMEOUT`, default 120 s). **Run tests one at a time** (the runner does). Several CEF test processes at once were avoided because they were suspected of making tests hang; that was not confirmed. `xvfb-run` and `xdotool` are needed (`t_views_input` skips itself without `xdotool`).

`tests/t_examples.php` runs the programs in `examples/`.

### What the tests cover

| Group | Tests |
| --- | --- |
| The extension itself | `t_core` (start and stop), `t_exceptions` (exceptions in handlers), `t_runtime` (time values, maps, `Buffer`, object identity, lifetime), `t_mapping` (one method per kind of argument and result, and the errors for wrong types), `t_sync_timeout`, `t_no_runtime` |
| Generated code | `t_stub_consistency` (the stub against Reflection), `t_generated_fresh` (the generated files are current), `t_generator` (unit tests of `tools/gen`, `tests/gen/test_generator.py`) |
| Tools and scripts | `t_runtime_tool` (`bin/cefloom-runtime`), `t_package` (`tools/package.sh`), `t_build` (`tools/build.sh`, with fake compilers), `t_runner`, `t_lint`, `t_docs` (the links and the PHP examples of `docs/`) |
| Pure library classes | `t_values`, `t_lib_values`, `t_structs`, `t_lib_pure` (encoding, URLs, files, ZIP, XML, streams, command line, images, post data, events, threads) |
| Network | `t_requests` and `t_resources` (the callbacks of a request, resource and scheme handlers), `t_urlrequest`, `t_cookies`, `t_context` (request contexts and preferences), `t_download`, `t_server`, `t_ssl` |
| Browser and handlers | `t_browser_*`, `t_browser_host` (zoom, find, DevTools, history, popups), `t_handlers`, `t_dialogs`, `t_input` (keyboard, focus, context menu), `t_osr` (off-screen rendering with pixel checks), `t_app_misc`, `t_bridge`, `t_bridge_more`, `t_thread_tasks` |
| Views, printing, Cast | `t_views`, `t_views_input`, `t_views_more`, `t_print`, `t_pdf`, `t_cast` |

The network tests talk to a local HTTP server (`tests/fixtures/server.php`, started by `start_http_server()` on a free port) and, for TLS, to a PHP child process with a certificate that `openssl` makes (`start_https_server()`). Nothing leaves the machine. Tests that need `xdotool` or `openssl` skip themselves without it.

A test that documents a known problem starts with a line `// xfail: reason`. The runner counts it as passed while it fails, and fails it when it passes ("unexpectedly passed"), which is the sign to turn it into an ordinary test and to update `llm-wiki/pages/reference/known-constraints.md`.

Writing a test: `require __DIR__ . '/support.php';`, call `start_cef()` once, use `check()` and `same()`, wait for CEF with `wait_until()` or `pump()` instead of sleeping, and end with `Cef::shutdown(); finish();`. Run a new test ten times and in both loop modes before it is committed: a test that fails one time in ten costs more than it finds.

### Coverage

```sh
tools/coverage.sh              # builds build-coverage/ with gcov, runs the tests in both loop modes, prints the report
```

The report counts which methods of the PHP API the tests run (by area of CEF) and the line coverage of the hand-written C++, and compares them with `tests/coverage-baseline.json`: it exits with 1 if a number fell. After adding tests, raise the baseline with `python3 tools/coverage_report.py build-coverage --write-baseline tests/coverage-baseline.json`. It measures execution, not the quality of the checks. `llm-wiki/pages/analyses/test-coverage.md` has the latest numbers and `llm-wiki/pages/reference/test-plan.md` the plan to raise them.

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

## Building this documentation

The pages are Sphinx with the Read the Docs theme. The guide is the Markdown of `docs/` (read by MyST); the API reference is written from `php/cefloom.stub.php` by `tools/docs/gen_api.py` on every build, and the pages it writes (`docs/api/gen-*.rst`) are not kept in git.

```sh
pip install -r docs/requirements.txt
make -C docs html          # docs/_build/html/en/index.html; warnings are errors
make -C docs site          # both languages and a start page, as published
```

`make -C docs html-all` also builds the Korean site (`_build/html/ko`) from the catalogs in `docs/locale/ko`. The guide, the cookbook and the index are translated. The descriptions in the API reference are the text of the CEF headers and stay in English, and so do the docstrings of the generator (`api/generator`, read by autodoc). After the English text of a page changes, `make -C docs update-po` brings the catalogs up to date and shows the entries that need a translation.

The C++ comments are Doxygen comments and are not on the site: Doxygen is not installed on the machine that built the pages, and no Breathe setup exists.
