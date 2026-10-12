<?php
// tools/package.sh makes the two release files (the extension for PIE, the runtime for bin/cefloom-runtime) from a build. The
// tests run it on a fake dist (the real lazy module, small fake CEF files) and look at what comes out, and at what it refuses.
require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$script = $root . '/tools/package.sh';
$dist0 = getenv('CEFLOOM_DIST') ?: $root . '/build/dist';
$work = tmpdir('cefloom-package');
$cef = Cefloom\Cef::cefVersion();
$short = explode('+', $cef)[0];
$arch = match (php_uname('m')) { 'x86_64', 'amd64' => 'x86_64', default => 'arm64' };
$mm = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$version = preg_match('/#define CEFLOOM_VERSION "([^"]+)"/', (string) file_get_contents($root . '/ext/src/cefloom.cc'), $m) ? $m[1] : '?';

function fake_dist(string $dir, string $module, bool $withLocales = true): void
{
    mkdir($dir . '/locales', 0777, true);
    copy($module, $dir . '/cefloom.so');
    foreach (['cefloomsubprocess', 'libcef.so', 'icudtl.dat', 'resources.pak', 'v8_context_snapshot.bin', 'chrome_100_percent.pak'] as $f) {
        file_put_contents("$dir/$f", "fake $f");
    }
    if ($withLocales) {
        file_put_contents("$dir/locales/en-US.pak", 'fake locale');
        file_put_contents("$dir/locales/ko.pak", 'fake locale');
    }
}
function package(string $script, string $dist, string $out, array $env = []): array
{
    $e = '';
    foreach (['CEFLOOM_DIST' => $dist, 'OUT' => $out] + $env as $k => $v) {
        $e .= ' ' . $k . '=' . escapeshellarg($v);
    }
    exec('env' . $e . ' bash ' . escapeshellarg($script) . ' 2>&1', $lines, $code);
    return [$code, implode("\n", $lines)];
}

// ---- a good run
$dist = "$work/dist";
fake_dist($dist, $dist0 . '/cefloom.so');
$out = "$work/out";
[$code, $text] = package($script, $dist, $out);
check($code === 0, "package.sh succeeds ($code: $text)");
$zip = "php_cefloom-{$version}_php{$mm}-{$arch}-linux-glibc-nts.zip";
$tar = "cefloom-runtime-$short-linux-$arch.tar.gz";
$names = array_map('basename', glob("$out/*"));
sort($names);
same([$tar, "$tar.sha256", $zip, "$zip.sha256"], $names, 'exactly the two release files and their checksums');
check(preg_match('/^php_cefloom-[0-9][0-9.]*_php\d+\.\d+-(x86_64|arm64)-linux-glibc-(debug-)?(nts|zts)\.zip$/', $zip) === 1, "the extension file name follows PIE's rule ($zip)");

// the extension zip: cefloom.so at the top, nothing else
exec('unzip -Z1 ' . escapeshellarg("$out/$zip") . ' 2>&1', $entries, $code);
same([0, ['cefloom.so']], [$code, $entries], 'the zip holds cefloom.so at the top level and nothing else');
same(sha1_file($dist . '/cefloom.so'), sha1((string) shell_exec('unzip -p ' . escapeshellarg("$out/$zip") . ' cefloom.so')), 'and it is the module of the dist');

// the runtime: unpacks with PHP's own PharData, the manifest lists what is there
$unpack = "$work/unpacked";
mkdir($unpack);
(new PharData("$out/$tar"))->extractTo($unpack);
$manifest = json_decode((string) file_get_contents("$unpack/cefloom-runtime.json"), true);
same([$cef, $arch], [$manifest['cef'] ?? null, $manifest['arch'] ?? null], 'the manifest says the CEF version and the architecture');
$found = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($unpack, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) { $found[] = substr($f->getPathname(), strlen($unpack) + 1); }
$listed = $manifest['files'] ?? [];
sort($found);
sort($listed);
same(array_values(array_diff($found, ['cefloom-runtime.json'])), $listed, 'the manifest lists exactly the files of the archive');
foreach (['cefloomsubprocess', 'libcef.so', 'icudtl.dat', 'resources.pak', 'v8_context_snapshot.bin', 'locales/en-US.pak', 'locales/ko.pak'] as $f) {
    check(in_array($f, $listed, true), "the runtime has $f");
}
check(!in_array('cefloom.so', $listed, true), 'the module is not part of the runtime');
check(!is_file("$unpack/vk_swiftshader_icd.json") && !in_array('libvulkan.so.1', $listed, true), 'files the dist lacks (optional ones) are left out');

// the checksums are checkable with sha256sum
exec('cd ' . escapeshellarg($out) . ' && sha256sum -c ' . escapeshellarg("$zip.sha256") . ' ' . escapeshellarg("$tar.sha256") . ' 2>&1', $lines, $code);
check($code === 0, 'sha256sum -c accepts both checksum files: ' . implode(' ', $lines));

// the runtime file installs with the tool (the two halves of the release fit)
exec('XDG_DATA_HOME=' . escapeshellarg("$work/xdg") . ' ' . escapeshellarg(PHP_BINARY) . ' -d extension=' . escapeshellarg($dist . '/cefloom.so')
    . ' ' . escapeshellarg($root . '/bin/cefloom-runtime') . ' install --url=' . escapeshellarg("$out/$tar") . ' --dir=' . escapeshellarg("$work/installed") . ' 2>&1', $lines2, $code);
check($code === 0 && is_file("$work/installed/libcef.so"), 'bin/cefloom-runtime installs the archive that package.sh made: ' . implode(' ', $lines2));

// a second run replaces the files (no old ones are left)
file_put_contents("$out/php_cefloom-0.0.1_php8.0-x86_64-linux-glibc-nts.zip", 'old');
[$code] = package($script, $dist, $out);
check($code === 0 && !file_exists("$out/php_cefloom-0.0.1_php8.0-x86_64-linux-glibc-nts.zip"), 'an earlier release file is removed by a new run');

// ---- what it refuses
$refuse = function (string $why, callable $prepare, string $message, array $env = []) use ($script, $work, $dist0): void {
    $d = "$work/refuse-" . bin2hex(random_bytes(3));
    fake_dist($d, $dist0 . '/cefloom.so');
    $prepare($d);
    [$code, $text] = package($script, $d, "$work/out-" . bin2hex(random_bytes(3)), $env);
    check($code !== 0 && str_contains($text, $message), "$why: refused with '$message' ($code: $text)");
};
$refuse('a file of the runtime is missing', fn($d) => unlink("$d/libcef.so"), 'missing');
$refuse('the locales are missing', function ($d) { array_map('unlink', glob("$d/locales/*")); rmdir("$d/locales"); }, 'missing');
$refuse('there is no module', fn($d) => unlink("$d/cefloom.so"), 'missing');
// a module that links libcef.so (the non-lazy build): make one that does
$cc = trim((string) shell_exec('command -v cc gcc 2>/dev/null | head -1'));
if ($cc !== '') {
    $refuse('the module links libcef.so', function ($d) use ($cc) {
        file_put_contents("$d/stub.c", 'int cef_stub(void) { return 0; }');
        exec(escapeshellarg($cc) . ' -shared -fPIC -o ' . escapeshellarg("$d/libcef.so") . ' ' . escapeshellarg("$d/stub.c") . ' 2>&1');
        exec(escapeshellarg($cc) . ' -shared -fPIC -o ' . escapeshellarg("$d/cefloom.so") . ' ' . escapeshellarg("$d/stub.c") . ' -L' . escapeshellarg($d) . ' -Wl,--no-as-needed -l:libcef.so 2>&1');
    }, 'links libcef.so');
}
// a PHP that is thread safe or a debug build, imitated: a wrapper that answers for those constants and runs the real PHP for the rest
foreach (['PHP_ZTS' => 'thread safe', 'PHP_DEBUG' => 'debug build'] as $constant => $message) {
    $fake = "$work/php-$constant";
    file_put_contents($fake, "#!/bin/sh\nfor a in \"\$@\"; do case \"\$a\" in *$constant*) echo 1; exit 0;; esac; done\nexec " . escapeshellarg(PHP_BINARY) . " \"\$@\"\n");
    chmod($fake, 0755);
    $refuse("a PHP that is $message", fn($d) => null, $message, ['PHP' => $fake]);
}

finish();
