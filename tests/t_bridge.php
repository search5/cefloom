<?php
// The JavaScript bridge: window.cefloom.call(name, ...args) reaches a PHP function exposed with Cef::expose().
require __DIR__ . '/support.php';

use Cefloom\{BrowserHost, BrowserSettings, Cef, Client, DisplayHandler, LifeSpanHandler, LoadHandler, WindowInfo};

$GLOBALS['calls'] = [];

final class Load extends LoadHandler
{
    public bool $started = false;
    public function onLoadEnd($browser, $frame, $httpStatusCode): void
    {
        if (!$frame->isMain() || $this->started) {
            return;
        }
        $this->started = true;
        $script = <<<'JS'
(async () => {
  const out = [];
  const attempt = async (label, ...call) => {
    try { out.push([label, 'ok', await cefloom.call(...call)]); }
    catch (e) { out.push([label, 'err', e.code, e.message]); }
  };
  await attempt('add', 'add', 2, 3);
  await attempt('info', 'info');
  await attempt('text', 'echo', '한글 ✓ "quote" \\ slash');
  await attempt('void', 'nothing');
  await attempt('throw', 'boom');
  await attempt('unknown', 'nope');
  await attempt('type', 'add', 'a', 'b');
  const both = await Promise.all([cefloom.call('add', 1, 1), cefloom.call('add', 2, 2), cefloom.call('add', 3, 3)]);
  out.push(['parallel', 'ok', both]);
  console.log('R:' + JSON.stringify(out));
})();
JS;
        $frame->executeJavaScript($script, '', 0);
    }
}

final class Display extends DisplayHandler
{
    public ?array $result = null;
    public function onConsoleMessage($browser, $level, $message, $source, $line): bool
    {
        if (str_starts_with($message, 'R:')) {
            $this->result = json_decode(substr($message, 2), true);
        }
        return false;
    }
}

final class Life extends LifeSpanHandler
{
    public bool $created = false;
    public function onAfterCreated($browser): void { $this->created = true; }
}

final class App extends Client
{
    public Load $load;
    public Display $display;
    public Life $life;
    public function __construct() { $this->load = new Load(); $this->display = new Display(); $this->life = new Life(); }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
    public function getDisplayHandler(): ?DisplayHandler { return $this->display; }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
}

start_cef();
Cef::expose('add', function (int $a, int $b): int { $GLOBALS['calls'][] = "add($a,$b)"; return $a + $b; });
Cef::expose('info', fn(): array => ['name' => 'x', 'list' => [1, 2, 3], 'nested' => ['a' => null, 'f' => 1.5, 'b' => true]]);
Cef::expose('echo', fn(string $s): string => $s);
Cef::expose('nothing', function (): void {});
Cef::expose('boom', function (): never { throw new RuntimeException('boom'); });
throws(fn() => Cef::expose('bad', 'no_such_function_anywhere'), TypeError::class, 'expose requires a callable');

$app = new App();
$info = new WindowInfo();
$info->bounds->width = 320;
$info->bounds->height = 240;
check(BrowserHost::createBrowser($info, $app, 'data:text/html,<title>bridge</title>', new BrowserSettings(), null, null), 'createBrowser');
wait_until(fn() => $app->life->created, 'browser created');
wait_until(fn() => $app->display->result !== null, 'the page finished its calls', 30000);

$r = $app->display->result ?? [];
$by = [];
foreach ($r as $row) {
    $by[$row[0]] = $row;
}
same(['add', 'ok', 5], $by['add'] ?? null, 'a call returns the PHP result');
same(['info', 'ok', ['name' => 'x', 'list' => [1, 2, 3], 'nested' => ['a' => null, 'f' => 1.5, 'b' => true]]], $by['info'] ?? null,
    'arrays, nested values, null, float and bool survive');
same(['text', 'ok', '한글 ✓ "quote" \\ slash'], $by['text'] ?? null, 'unicode and escapes survive');
same(['void', 'ok', null], $by['void'] ?? null, 'a function without result resolves to null');
same(['throw', 'err', 500, 'boom'], $by['throw'] ?? null, 'a PHP exception rejects with code 500 and its message');
same(['unknown', 'err', 404, 'unknown function'], $by['unknown'] ?? null, 'an unknown name rejects with 404');
check(($by['type'][1] ?? '') === 'err' && ($by['type'][2] ?? 0) === 500, 'a TypeError of the PHP function rejects with 500');
same(['parallel', 'ok', [2, 4, 6]], $by['parallel'] ?? null, 'parallel calls each get their own answer');
same(['add(2,3)', 'add(1,1)', 'add(2,2)', 'add(3,3)'], array_slice($GLOBALS['calls'], 0, 4), 'PHP saw the calls in order');

unset($info);
Cef::shutdown();
same(false, Cef::isRunning(), 'not running after shutdown');
finish();
