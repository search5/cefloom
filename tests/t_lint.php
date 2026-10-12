<?php
// The wiki checker (llm-wiki/lint.py) finds what it says it finds. The real wiki has to pass, and a copy of it that is
// broken in each way must be reported. The copy sits next to the repository, because the checker resolves the `sources`
// of the pages from the directory above the project.
require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$python = trim((string) shell_exec('command -v python3'));

function lint(string $python, string $wiki): array
{
    exec(escapeshellarg($python) . ' -I ' . escapeshellarg("$wiki/lint.py") . ' 2>&1', $lines, $code);
    return [$code, implode("\n", $lines)];
}

[$code, $out] = lint($python, "$root/llm-wiki");
if ($code !== 0 && preg_match('/source does not exist: (cef_origin|cefpython|java-cef|cefweaver_clone)\//', $out)) {
    echo "OK (0 checks) skipped: the wiki names sources of sibling projects that this checkout does not have\n";
    exit(0);
}
same(0, $code, 'the wiki passes its checker: ' . substr($out, 0, 300));
check(str_contains($out, '0 errors, 0 warnings'), 'with no warning');

$base = dirname($root) . '/cefloom-lint-' . getmypid();
register_shutdown_function(static function () use ($base): void { exec('rm -rf ' . escapeshellarg($base)); });
mkdir($base);
exec('cp -r ' . escapeshellarg("$root/llm-wiki") . ' ' . escapeshellarg("$base/llm-wiki"));
$wiki = "$base/llm-wiki";
[$code, $out] = lint($python, $wiki);
same(0, $code, 'the copy passes too: ' . substr($out, 0, 200));

/** Change one file of the copy, run the checker, undo the change. */
$broken = function (string $what, string $file, callable $change, string $needle, string $kind = 'error') use ($python, $wiki): void {
    $path = "$wiki/$file";
    $original = file_get_contents($path);
    file_put_contents($path, $change($original));
    [$code, $out] = lint($python, $wiki);
    file_put_contents($path, $original);
    $hit = str_contains($out, $needle) && str_contains($out, $kind . ':');
    check($hit && ($kind === 'warning' || $code === 1), "$what: reported" . ($hit ? '' : " (not found: $needle; got " . substr(str_replace("\n", ' | ', $out), 0, 160) . ')'));
};

$page = 'pages/concepts/project-goals.md';
$broken('a count in the index that is wrong', 'index.md', fn($s) => preg_replace('/D1부터 D\d+/', 'D1부터 D2', $s, 1), 'says 2 decisions');
$broken('a log entry that goes back in time', 'log.md', fn($s) => $s . "\n## [2020-01-01] ingest | old\n", 'comes after an entry dated');
$broken('a page missing from the index', 'index.md', fn($s) => preg_replace('/^- \[프로젝트 목표와 범위\].*\n/m', '', $s, 1), 'project-goals.md: not listed in index.md');
$broken('a broken link', $page, fn($s) => $s . "\n[x](no-such-page.md)\n", 'broken link: no-such-page.md');
$broken('a link that leaves the project', $page, fn($s) => $s . "\n[x](../../../../cef_origin/README.md)\n", 'link leaves cefloom');
$broken('a missing front matter field', $page, fn($s) => preg_replace('/^updated: .*\n/m', '', $s, 1), 'lacks `updated`');
$broken('a type that does not match the folder', $page, fn($s) => preg_replace('/^type: concept/m', 'type: analysis', $s, 1), 'does not match folder');
$broken('a source that does not exist', $page, fn($s) => preg_replace('/^sources:\n/m', "sources:\n  - cefloom/no/such/file.cc\n", $s, 1), 'source does not exist: cefloom/no/such/file.cc');
$broken('a log entry in the wrong form', 'log.md', fn($s) => $s . "\n## not a dated entry\n", 'log.md:');
$broken('a page over 200 lines', $page, fn($s) => $s . str_repeat("line\n", 210), 'over 200', 'warning');
$broken('an em dash', $page, fn($s) => $s . "\nthis \u{2014} is one\n", 'contains an em dash', 'warning');

[$code, $out] = lint($python, $wiki);
same(0, $code, 'after the changes are undone the copy passes again');

finish();
