<?php
// The rest of the bridge: requests that are not valid, a result that cannot be JSON, exposing a name twice, and
// createBrowser()/createBrowserSync() before CEF runs and createBrowserSync() after.
require __DIR__ . '/support.php';

use Cefloom\{BrowserHost, BrowserSettings, Cef, Client, DisplayHandler, LifeSpanHandler, LoadHandler, WindowInfo};

final class Display extends DisplayHandler
{
    public array $lines = [];
    public function onConsoleMessage($browser, $level, $message, $source, $line): bool { $this->lines[] = $message; return false; }
}
final class Life extends LifeSpanHandler
{
    public $browser = null;
    public function onAfterCreated($browser): void { $this->browser = $browser; }
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
}

$info = new WindowInfo();
$info->bounds->width = 320;
$info->bounds->height = 240;

// ---- CEF is not running yet
throws(fn() => BrowserHost::createBrowser($info, new App(), 'about:blank', new BrowserSettings(), null, null), Error::class, 'createBrowser() before initialize() throws');
throws(fn() => BrowserHost::createBrowserSync($info, new App(), 'about:blank', new BrowserSettings(), null, null), Error::class, 'createBrowserSync() before initialize() throws');

start_cef();
Cef::expose('first', fn(): string => 'one');
Cef::expose('first', fn(): string => 'two');   // the second one replaces the first
Cef::expose('nan', fn(): float => NAN);        // json_encode() cannot write it
Cef::expose('badutf', fn(): string => "\xff\xfe");
Cef::expose('boom', function (): void { throw new RuntimeException('bad input'); });

// ---- createBrowserSync() gives the browser at once
$app = new App();
$browser = BrowserHost::createBrowserSync($info, $app, 'data:text/html,<title>t</title>', new BrowserSettings(), null, null);
check($browser instanceof Cefloom\Browser, 'createBrowserSync() returns a Browser');
wait_until(fn() => $app->load->ends > 0, 'the page of the synchronous browser is loaded');
$frame = $browser->getMainFrame();

// ---- requests that are not valid come back as errors
$ask = function (string $request) use ($app, $frame): string {
    $before = count($app->display->lines);
    $js = 'window.cefQuery({request: ' . json_encode($request) . ', onSuccess: r => console.log("Q:ok " + r), onFailure: (c, m) => console.log("Q:" + c + " " + m)});';
    $frame->executeJavaScript($js, '', 0);
    wait_until(fn() => count($app->display->lines) > $before, 'the request is answered');
    return end($app->display->lines);
};
same('Q:400 invalid request', $ask('not json'), 'text that is not JSON gives 400');
same('Q:400 invalid request', $ask('42'), 'JSON that is not an object gives 400');
same('Q:404 unknown function', $ask('{"args":[]}'), 'no name gives 404');
same('Q:404 unknown function', $ask('{"name":7}'), 'a name that is not text gives 404');
same('Q:404 unknown function', $ask('{"name":"missing"}'), 'a name nobody exposed gives 404');
same('Q:ok "two"', $ask('{"name":"first"}'), 'exposing a name twice keeps the last function');
same('Q:ok "two"', $ask('{"name":"first","args":"x"}'), 'arguments that are not a list are ignored');
same('Q:500 the result cannot be converted to JSON', $ask('{"name":"nan"}'), 'a result that is not JSON gives 500');
same('Q:500 the result cannot be converted to JSON', $ask('{"name":"badutf"}'), 'text that is not UTF-8 gives 500');
same('Q:500 bad input', $ask('{"name":"boom"}'), 'an exception of the function gives 500 with its message');

Cef::shutdown();
finish();
