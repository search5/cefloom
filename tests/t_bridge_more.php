<?php
// The bridge beyond the basics (t_bridge.php): two browsers at once, calls from an iframe, 1 MB arguments and results,
// a browser that closes while its call is still waiting, and a call after another browser has gone.
require __DIR__ . '/support.php';

use Cefloom\{BrowserHost, BrowserSettings, Cef, Client, DisplayHandler, LifeSpanHandler, LoadHandler, WindowInfo};

$GLOBALS['hold'] = [];

final class Display extends DisplayHandler
{
    public array $lines = [];
    public function onConsoleMessage($browser, $level, $message, $source, $line): bool { $this->lines[] = $message; return false; }
}
final class Life extends LifeSpanHandler
{
    public $browser = null;
    public bool $closed = false;
    public function onAfterCreated($browser): void { $this->browser = $browser; }
    public function onBeforeClose($browser): void { $this->closed = true; }
}
final class Load extends LoadHandler
{
    public int $ends = 0;
    public function onLoadEnd($browser, $frame, $httpStatusCode): void { if ($frame->isMain()) { $this->ends++; } }
}
final class App extends Client
{
    public Display $display;
    public Life $life;
    public Load $load;
    public function __construct() { $this->display = new Display(); $this->life = new Life(); $this->load = new Load(); }
    public function getDisplayHandler(): ?DisplayHandler { return $this->display; }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
    /** Console lines starting with $prefix, without it. */
    public function said(string $prefix): array
    {
        $found = [];
        foreach ($this->display->lines as $l) {
            if (str_starts_with($l, $prefix)) { $found[] = substr($l, strlen($prefix)); }
        }
        return $found;
    }
}
function open_page(string $url): App
{
    $app = new App();
    $info = new WindowInfo();
    $info->bounds->width = 320;
    $info->bounds->height = 240;
    BrowserHost::createBrowser($info, $app, $url, new BrowserSettings(), null, null);
    wait_until(fn() => $app->life->browser !== null && $app->load->ends > 0, 'a page is loaded');
    return $app;
}
function run_js(App $app, string $code): void { $app->life->browser->getMainFrame()->executeJavaScript($code, '', 0); }

$base = start_http_server();
start_cef();
Cef::expose('who', fn(string $tag): string => "php:$tag");
Cef::expose('echo', fn(string $s): string => $s);
Cef::expose('size', fn(string $s): int => strlen($s));
Cef::expose('hold', function (): int { return count($GLOBALS['hold']); });
Cef::expose('close_me', function (): string { $GLOBALS['life']->browser->getHost()->closeBrowser(true); return 'closing'; });

// ---- two browsers use the bridge at the same time
$a = open_page('data:text/html,<title>a</title>');
$b = open_page('data:text/html,<title>b</title>');
run_js($a, "Promise.all([1,2,3].map(i => cefloom.call('who', 'a' + i))).then(r => console.log('R:' + JSON.stringify(r)));");
run_js($b, "Promise.all([1,2,3].map(i => cefloom.call('who', 'b' + i))).then(r => console.log('R:' + JSON.stringify(r)));");
wait_until(fn() => $a->said('R:') && $b->said('R:'), 'both browsers got their answers');
same([['php:a1', 'php:a2', 'php:a3']], array_map(fn($j) => json_decode($j, true), $a->said('R:')), 'browser A gets its own answers');
same([['php:b1', 'php:b2', 'php:b3']], array_map(fn($j) => json_decode($j, true), $b->said('R:')), 'browser B gets its own answers');

// ---- a call from an iframe (the bridge is in every frame)
$frameHtml = "<script>cefloom.call('who','iframe').then(v => console.log('F:' + v), e => console.log('F:err ' + e.message))</script>";
$c = open_page("$base/page?title=c&body=" . rawurlencode('<iframe srcdoc="' . htmlspecialchars($frameHtml, ENT_QUOTES) . '"></iframe>'));
wait_until(fn() => $c->said('F:'), 'the iframe called PHP', 15000);
same(['php:iframe'], $c->said('F:'), 'a call from an iframe reaches PHP');

// ---- big arguments and results
run_js($a, <<<'JS'
(async () => {
  const big = 'x'.repeat(1 << 20);
  const n = await cefloom.call('size', big);
  const back = await cefloom.call('echo', big);
  console.log('B:' + JSON.stringify([n, back.length, back === big]));
})();
JS);
wait_until(fn() => $a->said('B:'), 'the 1 MB round trip', 30000);
same([[1048576, 1048576, true]], array_map(fn($j) => json_decode($j, true), $a->said('B:')), 'a 1 MB argument and a 1 MB result make the round trip');

// ---- the other browser is not disturbed by a browser that goes away while its call is waiting
$GLOBALS['life'] = $c->life;
run_js($c, "cefloom.call('close_me').then(v => console.log('C:' + v), e => console.log('C:err ' + e.message));");
wait_until(fn() => $c->life->closed, 'the browser of the call closed');
check(true, 'closing the browser in the middle of its own call does not crash');
run_js($a, "cefloom.call('who', 'after').then(v => console.log('A:' + v));");
wait_until(fn() => $a->said('A:'), 'the other browser still works', 15000);
same(['php:after'], $a->said('A:'), 'a call from the browser that is left still works');

// ---- the page asks while PHP is shutting down
run_js($b, "cefloom.call('who', 'late').then(v => console.log('L:' + v), e => console.log('L:err ' + e.code + ' ' + e.message));");
Cef::shutdown();
same(false, Cef::isRunning(), 'shutdown with calls just made is clean');
finish();
