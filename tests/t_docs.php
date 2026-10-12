<?php
// The documentation (docs/) says what is true. Its links, headings and paths are checked (tools/check-docs.py, also on a
// copy that is broken in each way), and the code of the pages that carries a marker is run:
//   <!-- docs-test: same-as <file> -->   the next php block is that file, from its first `use Cefloom` line
//   <!-- docs-test: run <name> -->       the next php block runs inside the harness of that name (below) and does what it says
require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$python = trim((string) shell_exec('command -v python3'));
check($python !== '', 'python3 is there');

function check_docs(string $python, string $root, ?string $docs = null): array
{
    exec(escapeshellarg($python) . ' -I ' . escapeshellarg("$root/tools/check-docs.py") . ($docs ? ' ' . escapeshellarg($docs) : '') . ' 2>&1', $lines, $code);
    return [$code, implode("\n", $lines)];
}

[$code, $out] = check_docs($python, $root);
same(0, $code, 'the pages of docs/ have no broken link, heading or path: ' . $out);

// the checker notices each kind of problem
$tmp = tmpdir('cefloom-docs');
mkdir("$tmp/proj/docs", 0777, true);
mkdir("$tmp/proj/examples", 0777, true);
file_put_contents("$tmp/proj/examples/real.php", '<?php');
file_put_contents("$tmp/proj/docs/a.md", "# A\n\n## Part one\n\nSee [b](b.md#the-heading), [self](#part-one) and `examples/real.php`.\n\n```sh\n[x](nothing.md) `examples/none.php`\n```\n");
file_put_contents("$tmp/proj/docs/b.md", "# B\n\n## The heading\n\ntext\n");
[$code, $out] = check_docs($python, $root, "$tmp/proj/docs");
same(0, $code, 'a good set of pages passes (' . $out . ')');
file_put_contents("$tmp/proj/docs/a.md", "# A\n\n[gone](missing.md) [bad](b.md#no-such-heading) [loose](#nowhere) `examples/none.php`\n");
[$code, $out] = check_docs($python, $root, "$tmp/proj/docs");
check($code === 1 && str_contains($out, 'missing.md') && str_contains($out, 'b.md#no-such-heading') && str_contains($out, '#nowhere') && str_contains($out, 'examples/none.php'),
    'a missing file, a missing heading and a missing path are reported: ' . str_replace("\n", ' | ', $out));

// the marked code
$docs = glob("$root/docs/*.md");
$found = [];
foreach ($docs as $file) {
    $text = file_get_contents($file);
    if (preg_match_all('/<!-- docs-test: (same-as|run) (\S+) -->\s*```php\n(.*?)```/s', $text, $all, PREG_SET_ORDER)) {
        foreach ($all as $m) {
            $found[] = [basename($file), $m[1], $m[2], rtrim($m[3], "\n")];
        }
    }
}
check(count($found) >= 3, 'the pages carry marked code blocks (' . count($found) . ')');

$prelude = "<?php\nrequire " . var_export(__DIR__ . '/support.php', true) . ";\n";   // no `use`: the snippets bring their own

$harness = [
    // handlers.md: a scheme served by PHP; the page of app://test/ loads with status 200
    'scheme' => function (string $snippet) use ($prelude): string {
        [$head, $calls] = explode('Cef::registerCustomScheme', $snippet, 2);
        return $prelude . $head . '
final class L extends \\Cefloom\\LoadHandler { public ?int $status = null; function onLoadEnd($b, $f, $c): void { if ($f->isMain()) $this->status = $c; } }
final class A extends \\Cefloom\\Client { public L $l; function __construct() { $this->l = new L; } function getLoadHandler(): ?\\Cefloom\\LoadHandler { return $this->l; } }
\\Cefloom\\Cef::addSwitch("ozone-platform", "x11"); \\Cefloom\\Cef::addSwitch("disable-gpu");
Cef::registerCustomScheme' . $calls . '
$a = new A; $i = new \\Cefloom\\WindowInfo(); $i->bounds->width = 300; $i->bounds->height = 200;
\\Cefloom\\BrowserHost::createBrowser($i, $a, "app://test/index.html", new \\Cefloom\\BrowserSettings(), null, null);
$end = microtime(true) + 20; while (microtime(true) < $end && $a->l->status === null) { \\Cefloom\\Cef::doMessageLoopWork(); \\Cefloom\\Cef::wait(5); }
echo "status=", var_export($a->l->status, true), "\n";
\\Cefloom\\Cef::shutdown();
';
    },
    // javascript-bridge.md: the functions of the page are called from JavaScript; the results come back through the console
    'bridge' => function (string $snippet) use ($prelude): string {
        return $prelude . 'use Cefloom\\{BrowserHost, BrowserSettings, Cef, Client, DisplayHandler, LoadHandler, WindowInfo};
final class L extends LoadHandler { public bool $go = false; function onLoadEnd($b, $f, $c): void { if ($f->isMain() && !$this->go) { $this->go = true; $f->executeJavaScript(\'(async () => { const sum = await cefloom.call("add", 2, 3); const user = await cefloom.call("user", "42"); let code = null; try { await cefloom.call("boom"); } catch (e) { code = e.code; } console.log("R:" + JSON.stringify([sum, user, code])); })()\', \'\', 0); } } }
final class D extends DisplayHandler { public ?string $r = null; function onConsoleMessage($b, $l, $m, $s, $n): bool { if (str_starts_with($m, "R:")) $this->r = substr($m, 2); return false; } }
final class A extends Client { public L $l; public D $d; function __construct() { $this->l = new L; $this->d = new D; } function getLoadHandler(): ?LoadHandler { return $this->l; } function getDisplayHandler(): ?DisplayHandler { return $this->d; } }
Cef::addSwitch("ozone-platform", "x11"); Cef::addSwitch("disable-gpu");
' . $snippet . '
Cef::expose("boom", function () { throw new RuntimeException("boom"); });
$a = new A; $i = new WindowInfo(); $i->bounds->width = 300; $i->bounds->height = 200;
BrowserHost::createBrowser($i, $a, "data:text/html,<title>x</title>", new BrowserSettings(), null, null);
$end = microtime(true) + 20; while (microtime(true) < $end && $a->d->r === null) { Cef::doMessageLoopWork(); Cef::wait(5); }
echo "result=", $a->d->r, "\n";
Cef::shutdown();
';
    },
];
$expected = [
    'scheme' => 'status=200',
    'bridge' => 'result=[5,{"id":"42","name":"Ada","tags":["admin"]},500]',
];

foreach ($found as [$page, $kind, $arg, $code]) {
    $where = "$page ($kind $arg)";
    if ($kind === 'same-as') {
        $file = "$root/$arg";
        $text = is_file($file) ? file_get_contents($file) : '';
        $body = str_contains($text, 'use Cefloom') ? 'use Cefloom' . explode('use Cefloom', $text, 2)[1] : '';
        same(rtrim($body, "\n"), $code, "$where: the code is the file");
    } else {
        if (!isset($harness[$arg])) {
            check(false, "$where: there is no harness of that name");
            continue;
        }
        $script = tmpdir('cefloom-docs-run') . '/snippet.php';
        file_put_contents($script, $harness[$arg]($code));
        $cache = tmpdir('cefloom-docs-cache');
        [$exit, $output] = run_php($script, [], ['XDG_CACHE_HOME' => $cache], 90);
        check(str_contains($output, $expected[$arg]), "$where: the code does what the page says (" . substr(str_replace("\n", ' | ', $output), 0, 200) . ')');
    }
}

// the recipes of docs/cookbook.md come from one program: it prints what the page says it prints
$script = "$root/docs/recipes/cookbook.php";
check(is_file($script), 'docs/recipes/cookbook.php exists');
[$exit, $output] = run_php($script, [], ['XDG_CACHE_HOME' => tmpdir('cefloom-docs-cache')], 90);
same("title: sum=5\nevaluate: hello\npdf: true", trim($output), 'the cookbook program prints what docs/cookbook.md says (' . substr(str_replace("\n", ' | ', $output), 0, 200) . ')');
same(0, $exit, 'the cookbook program exits with 0');

finish();
