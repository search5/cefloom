<?php
// What a set of command line switches does to CEF on this machine: starts CEF with the switches named in $PROBE_SWITCHES
// (comma separated, name or name=value), loads a page served by PHP that asks for a WebGL and a WebGL 2 context, and prints
// whether Cef::initialize() worked, what the page found, and the CEF processes that are running. docs/wayland-gpu.md
// quotes its output.
//
//   PROBE_SWITCHES=ozone-platform=x11,disable-gpu xvfb-run -a php -d extension=build/dist/cefloom.so docs/recipes/gpu-probe.php
use Cefloom\{Browser, BrowserHost, BrowserSettings, Cef, Client, WindowInfo, Settings, Frame, LoadHandler, Callback, Request, ResourceHandler, Buffer, Response, ResourceReadCallback, SchemeHandlerFactory};
use Cefloom\Types\SchemeOptions;

const PAGE = <<<'HTML'
<!doctype html><title>probe</title><script>
function gl(kind) {
  try {
    const c = document.createElement('canvas');
    const g = c.getContext(kind);
    if (!g) return 'none';
    const e = g.getExtension('WEBGL_debug_renderer_info');
    return e ? g.getParameter(e.UNMASKED_RENDERER_WEBGL) : 'yes (no renderer info)';
  } catch (x) { return 'error ' + x; }
}
cefloom.call('report', JSON.stringify({webgl: gl('webgl'), webgl2: gl('webgl2'), gpu: String('gpu' in navigator)}));
</script>
HTML;

final class Page extends ResourceHandler {
    private int $pos = 0;
    public function open(Request $r, Callback $c): array { return [true, true]; }
    public function getResponseHeaders(Response $r): array { $r->setStatus(200); $r->setMimeType('text/html'); return [strlen(PAGE), '']; }
    public function read(Buffer $out, ResourceReadCallback $cb): array {
        if ($this->pos >= strlen(PAGE)) return [false, 0];
        $n = $out->write(substr(PAGE, $this->pos, $out->getSize())); $this->pos += $n; return [true, $n];
    }
    public function cancel(): void {}
}
final class Factory extends SchemeHandlerFactory {
    public function create(?Browser $b, ?Frame $f, string $s, Request $r): ?ResourceHandler { return new Page(); }
}

function types(): string {
    $t = [];
    foreach (glob('/proc/[0-9]*/cmdline') as $f) {
        $c = @file_get_contents($f);
        if ($c && str_contains($c, 'cefloomsubprocess') && preg_match('/--type=([a-z-]+)/', str_replace("\0", ' ', $c), $m)) {
            $t[$m[1]] = ($t[$m[1]] ?? 0) + 1;
        }
    }
    ksort($t);
    return json_encode($t);
}

foreach (array_filter(explode(',', (string)getenv('PROBE_SWITCHES'))) as $s) {
    [$n, $v] = array_pad(explode('=', $s, 2), 2, '');
    Cef::addSwitch($n, $v);
}
Cef::registerCustomScheme('app', SchemeOptions::STANDARD | SchemeOptions::SECURE | SchemeOptions::CORS_ENABLED);
$settings = new Settings();
$settings->rootCachePath = sys_get_temp_dir() . '/gpu-probe-cache-' . getmypid();
$ok = Cef::initialize($settings);
echo "initialize: ", var_export($ok, true), "\n";
if (!$ok) exit(1);
Cef::registerSchemeHandlerFactory('app', 'test', new Factory());
$got = null;
Cef::expose('report', function (string $s) use (&$got) { $got = $s; });
$info = new WindowInfo();
$info->bounds->width = 400; $info->bounds->height = 300;
BrowserHost::createBrowser($info, new Client(), 'app://test/index.html', new BrowserSettings(), null, null);
$end = microtime(true) + 12;
while ($got === null && microtime(true) < $end) { Cef::doMessageLoopWork(); Cef::wait(5); }
echo "report: ", $got ?? 'none (timeout)', "\n";
echo "processes: ", types(), "\n";
Cef::shutdown();
exec('rm -rf ' . escapeshellarg($settings->rootCachePath));
