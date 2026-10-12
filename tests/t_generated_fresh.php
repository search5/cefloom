<?php
// The generated files (ext/generated, php/cefloom.stub.php, the coverage report of the wiki) are what tools/gen makes
// from the CEF headers now: nobody edited them by hand and nobody changed the generator without regenerating. The
// check of the generator (generate.py --check) is tried on the repository, and on a copy that is changed in each way
// it has to notice. Skipped when the CEF distribution that the generator reads is not there.
require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$cef = getenv('CEF_ROOT') ?: dirname($root) . '/cefweaver_clone/cefweaver/build/native/cef';
if (!is_file("$cef/include/cef_version.h")) {
    echo "OK (0 checks) skipped: the CEF distribution for the generator was not found ($cef; set CEF_ROOT)\n";
    exit(0);
}

function generate_check(string $root, string $cef): array
{
    exec('python3 -I ' . escapeshellarg("$root/tools/gen/generate.py") . ' --check --cef-root ' . escapeshellarg($cef) . ' 2>&1', $lines, $code);
    return [$code, implode("\n", $lines)];
}

[$code, $out] = generate_check($root, $cef);
same([0, ''], [$code, $out], 'the repository is up to date with its generator' . ($out !== '' ? ": $out" : ''));

// a copy of what the generator reads and writes (it finds its output directories relative to itself)
$tmp = tmpdir('cefloom-generated');
$copy = "$tmp/cefloom";
mkdir("$copy/ext", 0777, true);
mkdir("$copy/llm-wiki/pages", 0777, true);
foreach (['tools', 'php'] as $d) {
    exec('cp -r ' . escapeshellarg("$root/$d") . ' ' . escapeshellarg("$copy/$d"));
}
exec('cp -r ' . escapeshellarg("$root/ext/generated") . ' ' . escapeshellarg("$copy/ext/generated"));
exec('cp -r ' . escapeshellarg("$root/llm-wiki/pages/reference") . ' ' . escapeshellarg("$copy/llm-wiki/pages/reference") . ' 2>/dev/null || (mkdir -p ' . escapeshellarg("$copy/llm-wiki/pages") . ' && cp -r ' . escapeshellarg("$root/llm-wiki/pages/reference") . ' ' . escapeshellarg("$copy/llm-wiki/pages/") . ')');
exec('rm -rf ' . escapeshellarg("$copy/tools/gen/__pycache__"));

[$code, $out] = generate_check($copy, $cef);
same([0, ''], [$code, $out], 'a copy of the repository is up to date too' . ($out !== '' ? ": $out" : ''));

$expect = function (string $what, string $needle) use ($copy, $cef): void {
    [$code, $out] = generate_check($copy, $cef);
    check($code === 1 && str_contains($out, $needle), "$what is noticed (exit $code: " . substr(str_replace("\n", ' | ', $out), 0, 120) . ')');
};

$file = "$copy/ext/generated/lib_CefFrame.cc";
$original = file_get_contents($file);
file_put_contents($file, $original . "// edited by hand\n");
$expect('a generated file edited by hand', 'out of date: ext/generated/lib_CefFrame.cc');
file_put_contents($file, $original);

unlink("$copy/ext/generated/lib_CefView.cc");
$expect('a generated file that is missing', 'ext/generated/lib_CefView.cc');
file_put_contents("$copy/ext/generated/lib_CefView.cc", file_get_contents("$root/ext/generated/lib_CefView.cc"));

file_put_contents("$copy/ext/generated/zz_left_over.cc", "// a file the generator no longer makes\n");
$expect('a file the generator no longer makes', 'zz_left_over.cc');
unlink("$copy/ext/generated/zz_left_over.cc");

$stub = "$copy/php/cefloom.stub.php";
$original = file_get_contents($stub);
file_put_contents($stub, str_replace('public static function run(', 'public static function runX(', $original));
$expect('a stub edited by hand', 'php/cefloom.stub.php');
file_put_contents($stub, $original);

$report = "$copy/llm-wiki/pages/reference/coverage-report.md";
$original = file_get_contents($report);
file_put_contents($report, $original . "\nedited\n");
$expect('the coverage report edited by hand', 'coverage-report.md');
file_put_contents($report, $original);

[$code, $out] = generate_check($copy, $cef);
same([0, ''], [$code, $out], 'after the changes are undone the copy is up to date again');

finish();
