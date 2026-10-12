<?php
// bin/cefloom-runtime installs the CEF runtime the extension loads. The tests build small fake runtimes (an archive with the
// manifest the real one has) and run the tool against them, over files: no network and no real CEF files.
require __DIR__ . '/support.php';

$tool = dirname(__DIR__) . '/bin/cefloom-runtime';
$cef = Cefloom\Cef::cefVersion();
$short = explode('+', $cef)[0];
$arch = match (php_uname('m')) { 'x86_64', 'amd64' => 'x86_64', default => 'arm64' };
$name = "cefloom-runtime-$short-linux-$arch.tar.gz";
$work = tmpdir('cefloom-runtime-tool');

/** Make a runtime archive. $manifest: null = none, an array = its content. Returns the path (and writes <path>.sha256 if asked). */
function make_archive(string $dir, string $name, ?array $manifest, array $files = ['libcef.so' => 'fake', 'cefloomsubprocess' => 'fake'], bool $sidecar = false): string
{
    $src = "$dir/src-" . bin2hex(random_bytes(3));
    mkdir($src, 0777, true);
    foreach ($files as $f => $content) {
        file_put_contents("$src/$f", $content);
    }
    if ($manifest !== null) {
        file_put_contents("$src/cefloom-runtime.json", json_encode($manifest));
    }
    $tar = "$dir/" . bin2hex(random_bytes(3)) . '.tar';
    $phar = new PharData($tar);
    $phar->buildFromDirectory($src);
    $phar->compress(Phar::GZ);
    $archive = "$dir/$name";
    @mkdir(dirname($archive), 0777, true);
    rename("$tar.gz", $archive);
    unlink($tar);
    if ($sidecar) {
        file_put_contents("$archive.sha256", hash_file('sha256', $archive) . "  $name\n");
    }
    return $archive;
}

/** Run the tool with the module copied alone into a new directory: no runtime beside it, so only CEFLOOM_HOME and XDG_DATA_HOME count. */
function tool(string $tool, array $args, array $env = []): array
{
    static $module = null;
    if ($module === null) {
        $dir = tmpdir('cefloom-lone-module');
        $module = "$dir/cefloom.so";
        copy((getenv('CEFLOOM_DIST') ?: dirname(__DIR__) . '/build/dist') . '/cefloom.so', $module);
    }
    return run_php($tool, $args, $env, 60, $module);
}

$good = ['cef' => $cef, 'files' => ['libcef.so', 'cefloomsubprocess']];
$home = "$work/home";            // where the tool installs when no --dir is given (XDG_DATA_HOME)
$env = ['XDG_DATA_HOME' => $home, 'CEFLOOM_HOME' => null, 'CEFLOOM_RUNTIME_URL' => null, 'CEFLOOM_RUNTIME_BASE_URL' => null];
$userDir = "$home/cefloom/runtime/$short";   // under XDG_DATA_HOME, by the short CEF version (Cef::userRuntimeDirectory())

// ---- usage and arguments
[$code, $out] = tool($tool, [], $env);
check($code === 0 && str_contains($out, 'usage: cefloom-runtime'), 'no command prints the usage and exits 0');
[$code, $out] = tool($tool, ['nonsense'], $env);
check($code === 2 && str_contains($out, 'usage:'), 'an unknown command exits 2');
[$code, $out] = tool($tool, ['install', 'stray'], $env);
check($code === 1 && str_contains($out, 'unexpected argument: stray'), 'a stray argument is refused');
[$code, $out] = tool($tool, ['install'], $env);
check($code === 1 && str_contains($out, "--url=<archive> or --base-url") && str_contains($out, $name), 'install without a source says what to give, and the archive name');

// ---- a good install, with --sha256, into --dir
$archive = make_archive($work, 'good.tar.gz', $good);
$sha = hash_file('sha256', $archive);
$dir1 = "$work/rt1";
[$code, $out] = tool($tool, ['install', "--url=$archive", "--sha256=$sha", "--dir=$dir1"], $env);
check($code === 0 && str_contains($out, "Installed the CEF runtime in $dir1"), "install --url --sha256 --dir succeeds ($code: $out)");
check(is_file("$dir1/libcef.so") && is_file("$dir1/cefloom-runtime.json"), 'the files are in place');
check(is_executable("$dir1/cefloomsubprocess") && is_executable("$dir1/libcef.so"), 'the subprocess and the library are executable');
check(glob("$dir1.tmp-*") === [], 'no working directory is left');

// ---- already installed; --force
file_put_contents("$dir1/marker", 'x');
[$code, $out] = tool($tool, ['install', "--url=$archive", "--sha256=$sha", "--dir=$dir1"], $env);
check($code === 0 && str_contains($out, 'already installed') && is_file("$dir1/marker"), 'an installed runtime is left as it is');
[$code, $out] = tool($tool, ['install', "--url=$archive", "--sha256=$sha", "--dir=$dir1", '--force'], $env);
check($code === 0 && !is_file("$dir1/marker") && is_file("$dir1/libcef.so"), '--force installs again, replacing what was there');

// ---- the checksum
$dir2 = "$work/rt2";
[$code, $out] = tool($tool, ['install', "--url=$archive", '--sha256=' . str_repeat('0', 64), "--dir=$dir2"], $env);
check($code === 1 && str_contains($out, 'does not match') && !file_exists($dir2) && glob("$dir2.tmp-*") === [], 'a wrong checksum installs nothing and cleans up');
[$code, $out] = tool($tool, ['install', "--url=$archive", '--sha256=abc', "--dir=$dir2"], $env);
check($code === 1 && str_contains($out, 'no SHA-256 checksum'), 'a checksum that is not 64 hex digits is refused');
[$code, $out] = tool($tool, ['install', "--url=$archive", "--dir=$dir2"], $env);
check($code === 1 && !file_exists($dir2), 'without --sha256 and without a .sha256 next to the archive nothing is installed');
$sidecarArchive = make_archive($work, 'side.tar.gz', $good, sidecar: true);
[$code, $out] = tool($tool, ['install', "--url=$sidecarArchive", "--dir=$dir2"], $env);
check($code === 0 && is_file("$dir2/libcef.so"), 'the checksum is read from <archive>.sha256');
file_put_contents("$sidecarArchive.sha256", str_repeat('f', 64) . "\n");
[$code, $out] = tool($tool, ['install', "--url=$sidecarArchive", '--dir=' . "$work/rt3"], $env);
check($code === 1 && str_contains($out, 'does not match'), 'a wrong checksum in the sidecar is caught');
$upper = make_archive($work, 'upper.tar.gz', $good);
[$code] = tool($tool, ['install', "--url=$upper", '--sha256=' . strtoupper(hash_file('sha256', $upper)), "--dir=$work/rt-upper"], $env);
check($code === 0, 'the checksum may be written in capitals');

// ---- the content of the archive
$other = make_archive($work, 'other.tar.gz', ['cef' => '1.0.0+g0+chromium-1.0', 'files' => ['libcef.so']]);
[$code, $out] = tool($tool, ['install', "--url=$other", '--sha256=' . hash_file('sha256', $other), "--dir=$work/rt4"], $env);
check($code === 1 && str_contains($out, 'holds CEF 1.0.0') && str_contains($out, "needs $cef") && !file_exists("$work/rt4"), 'a runtime of another CEF version is refused');
$bare = make_archive($work, 'bare.tar.gz', null);
[$code, $out] = tool($tool, ['install', "--url=$bare", '--sha256=' . hash_file('sha256', $bare), "--dir=$work/rt5"], $env);
check($code === 1 && str_contains($out, 'no manifest') && !file_exists("$work/rt5"), 'an archive without a manifest is refused');
$lacking = make_archive($work, 'lacking.tar.gz', ['cef' => $cef, 'files' => ['libcef.so', 'missing.pak']]);
[$code, $out] = tool($tool, ['install', "--url=$lacking", '--sha256=' . hash_file('sha256', $lacking), "--dir=$work/rt6"], $env);
check($code === 1 && str_contains($out, 'lacks missing.pak') && !file_exists("$work/rt6"), 'a file the manifest lists but the archive lacks is refused');
$broken = "$work/broken.tar.gz";
file_put_contents($broken, random_bytes(2048));
[$code, $out] = tool($tool, ['install', "--url=$broken", '--sha256=' . hash_file('sha256', $broken), "--dir=$work/rt7"], $env);
check($code === 1 && str_contains($out, 'cannot unpack') && !file_exists("$work/rt7") && glob("$work/rt7.tmp-*") === [], 'a broken archive is refused and cleaned up');

// ---- where it comes from
[$code, $out] = tool($tool, ['install', "--url=$work/nothing.tar.gz", '--sha256=' . str_repeat('a', 64), "--dir=$work/rt8"], $env);
check($code === 1 && str_contains($out, 'cannot open'), 'a missing archive is reported');
$release = "$work/release";
make_archive($release, $name, $good, sidecar: true);
[$code, $out] = tool($tool, ['install', "--base-url=$release/", "--dir=$work/rt9"], $env);
check($code === 0 && is_file("$work/rt9/libcef.so"), '--base-url finds the archive by its name, and its sidecar');
[$code, $out] = tool($tool, ['install', "--url=file://$release/$name", "--dir=$work/rt10"], $env);
check($code === 0 && is_file("$work/rt10/libcef.so"), 'a file:// URL works');
[$code, $out] = tool($tool, ['install', "--dir=$work/rt11"], array_merge($env, ['CEFLOOM_RUNTIME_BASE_URL' => $release]));
check($code === 0 && is_file("$work/rt11/libcef.so"), 'CEFLOOM_RUNTIME_BASE_URL is used when no option is given');
[$code, $out] = tool($tool, ['install', "--dir=$work/rt12"], array_merge($env, ['CEFLOOM_RUNTIME_URL' => "$release/$name"]));
check($code === 0 && is_file("$work/rt12/libcef.so"), 'CEFLOOM_RUNTIME_URL is used when no option is given');

// ---- the default directory (under XDG_DATA_HOME), status and path
[$code, $out] = tool($tool, ['status'], $env);
check($code === 1 && str_contains($out, 'runtime present:           no') && str_contains($out, "CEF version of the module: $cef"), 'status says the runtime is missing, exit 1');
[$code, $out] = tool($tool, ['install', "--url=$release/$name"], $env);
check($code === 0 && is_file("$userDir/libcef.so"), "without --dir the runtime goes under XDG_DATA_HOME ($userDir)");
[$code, $out] = tool($tool, ['status'], $env);
check($code === 0 && str_contains($out, 'runtime present:           yes') && str_contains($out, "runtime CEF version:       $cef"), 'status of an installed runtime: yes, its CEF version, exit 0');
[$code, $out] = tool($tool, ['path'], $env);
check($code === 0 && trim($out) === $userDir, "path prints the directory the module loads from ($userDir)");
[$code, $out] = tool($tool, ['path'], array_merge($env, ['CEFLOOM_HOME' => $dir1]));
check($code === 0 && trim($out) === $dir1, 'CEFLOOM_HOME wins in path');

finish();
