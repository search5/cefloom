<?php
// tools/build.sh with the compiler and make replaced by scripts that only record what they were asked: what the script itself
// decides is tested (a failed compile stops the build and leaves the old module alone, a changed mode starts the extension build over,
// the runtime is copied where hard links cannot be made), and no real compile is made. phpize and configure run for real.
require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$cefRoot = getenv('CEF_ROOT') ?: dirname($root) . '/cefweaver_clone/cefweaver/build/native/cef';
if (!is_file("$cefRoot/include/cef_version.h")) {
    echo "OK (0 checks) skipped: no CEF headers at $cefRoot\n";
    exit(0);
}
$work = tmpdir('cefloom-build');

/** A directory of fake tools. $makeBehaviour: 'ok' makes modules/cefloom.so; 'fail' prints an error and fails. */
function fake_tools(string $dir, string $makeBehaviour, bool $hardLinksFail): void
{
    @mkdir($dir, 0777, true);
    $realCp = trim((string) shell_exec('command -v cp'));
    $make = $makeBehaviour === 'ok'
        ? "#!/bin/sh\necho make \"\$@\" >> \"\$FAKE_LOG\"\nmkdir -p modules && echo fake-module > modules/cefloom.so\nexit 0\n"
        : "#!/bin/sh\necho make \"\$@\" >> \"\$FAKE_LOG\"\necho 'foo.cc:1:1: error: nothing works'\nexit 2\n";
    file_put_contents("$dir/make", $make);
    // g++ writes the file after -o; patchelf only records
    file_put_contents("$dir/g++", "#!/bin/sh\necho g++ \"\$@\" >> \"\$FAKE_LOG\"\nwhile [ \$# -gt 0 ]; do if [ \"\$1\" = -o ]; then echo fake-subprocess > \"\$2\"; fi; shift; done\nexit 0\n");
    file_put_contents("$dir/patchelf", "#!/bin/sh\necho patchelf \"\$@\" >> \"\$FAKE_LOG\"\nexit 0\n");
    // a cp that cannot hard-link (as between two file systems) leaves the half made directory a real one leaves
    file_put_contents("$dir/cp", "#!/bin/sh\ncase \"\$1\" in -alf) " . ($hardLinksFail ? 'mkdir -p "$3"; exit 1' : 'exec ' . $realCp . ' "$@"') . " ;; esac\nexec $realCp \"\$@\"\n");
    foreach (['make', 'g++', 'patchelf', 'cp'] as $f) {
        chmod("$dir/$f", 0755);
    }
}
function fake_runtime(string $dir): void
{
    mkdir("$dir/locales", 0777, true);
    foreach (['libcef.so', 'icudtl.dat', 'resources.pak', 'locales/en-US.pak', 'locales/ko.pak', 'cefweaver.py', 'cefsubprocess'] as $f) {
        file_put_contents("$dir/$f", "fake $f");
    }
}
function build(string $root, string $tools, string $build, string $runtime, string $cefRoot, array $env = []): array
{
    $e = ['PATH' => "$tools:" . getenv('PATH'), 'BUILD' => $build, 'CEF_ROOT' => $cefRoot, 'CEFLOOM_RUNTIME' => $runtime,
        'CEFLOOM_WRAPPER_LIB' => "$build/wrapper.a", 'FAKE_LOG' => "$build/fake.log", 'JOBS' => '1'] + $env;
    $text = '';
    foreach ($e as $k => $v) {
        $text .= ' ' . $k . '=' . escapeshellarg((string) $v);
    }
    @mkdir($build, 0777, true);
    touch("$build/wrapper.a");
    exec('env' . $text . ' bash ' . escapeshellarg($root . '/tools/build.sh') . ' 2>&1', $lines, $code);
    return [$code, implode("\n", $lines)];
}

$runtime = "$work/runtime";
fake_runtime($runtime);

// ---- a compile that fails stops the build, shows the error, and leaves the module of an earlier build alone
$tools = "$work/tools-fail";
fake_tools($tools, 'fail', false);
$build = "$work/b1";
mkdir("$build/dist", 0777, true);
file_put_contents("$build/dist/cefloom.so", 'OLD MODULE');
[$code, $text] = build($root, $tools, $build, $runtime, $cefRoot);
check($code !== 0, "a failed make makes build.sh fail ($code)");
check(str_contains($text, 'error: nothing works') && str_contains($text, 'build failed'), 'the error of the compiler is shown, and "build failed"');
same('OLD MODULE', file_get_contents("$build/dist/cefloom.so"), 'the module of the earlier build is not replaced by anything');
check(!str_contains($text, 'built:'), 'it does not say it built');

// ---- the mode (lazy libcef, coverage) is part of the build: a change starts the extension build over, the same mode does not
file_put_contents("$build/ext/stale", 'x');
file_put_contents("$build/ext.mode", 'lazy=1 coverage=');
[$code] = build($root, $tools, $build, $runtime, $cefRoot);                    // same mode as recorded
check(is_file("$build/ext/stale"), 'the same mode keeps the extension build');
[$code] = build($root, $tools, $build, $runtime, $cefRoot, ['CEFLOOM_COVERAGE' => '1']);
check(!is_file("$build/ext/stale"), 'a changed mode (coverage) starts the extension build over');
same('lazy=1 coverage=1', file_get_contents("$build/ext.mode"), 'and the new mode is recorded');
file_put_contents("$build/ext/stale", 'x');
[$code] = build($root, $tools, $build, $runtime, $cefRoot, ['CEFLOOM_LAZY_LIBCEF' => '0', 'CEFLOOM_COVERAGE' => '1']);
check(!is_file("$build/ext/stale") && file_get_contents("$build/ext.mode") === 'lazy= coverage=1', 'linking libcef (CEFLOOM_LAZY_LIBCEF=0) is a changed mode too');

// ---- a good build with hard links possible: the runtime files are in dist, the Python parts of the runtime are not
$tools = "$work/tools-ok";
fake_tools($tools, 'ok', false);
$build = "$work/b2";
[$code, $text] = build($root, $tools, $build, $runtime, $cefRoot);
check($code === 0 && str_contains($text, "built: $build/dist"), "a build ends with 'built: ...' ($code: " . substr($text, -300) . ')');
same('fake-module', trim((string) file_get_contents("$build/dist/cefloom.so")), 'the module of make is installed in dist');
foreach (['libcef.so', 'icudtl.dat', 'resources.pak', 'locales/en-US.pak', 'locales/ko.pak', 'cefloomsubprocess'] as $f) {
    check(is_file("$build/dist/$f"), "dist has $f");
}
check(!file_exists("$build/dist/cefweaver.py") && !file_exists("$build/dist/cefsubprocess"), 'the Python files and the subprocess of cefweaver are left out');
$log = (string) file_get_contents("$build/fake.log");
check(str_contains($log, '-fno-stack-protector') && str_contains($log, 'cefloomsubprocess.cc') && str_contains($log, '-Wl,-rpath,$ORIGIN'), 'the subprocess is compiled without the stack protector and with $ORIGIN as rpath');
check(str_contains($log, "patchelf --set-rpath \$ORIGIN $build/dist/cefloom.so"), 'the module gets $ORIGIN as rpath');

// ---- hard links that cannot be made (another file system): the files are copied, and a directory is not copied into itself
$tools = "$work/tools-nolink";
fake_tools($tools, 'ok', true);
$build = "$work/b3";
[$code, $text] = build($root, $tools, $build, $runtime, $cefRoot);
check($code === 0, "the build works without hard links ($code: " . substr($text, -300) . ')');
check(is_file("$build/dist/locales/en-US.pak") && !file_exists("$build/dist/locales/locales"), 'locales/ is copied once, not into itself (the locales/locales regression)');
same('fake libcef.so', file_get_contents("$build/dist/libcef.so"), 'and files are copied');

// ---- a second run on the same build directory works
[$code] = build($root, $tools, $build, $runtime, $cefRoot);
check($code === 0 && is_file("$build/dist/locales/en-US.pak") && !file_exists("$build/dist/locales/locales"), 'a second run on the same directory works too');

finish();
