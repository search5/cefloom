<?php
// The life of CEF in a PHP process (ext/src/core.cc): what the functions do before CEF is started, when it is started
// twice or wrongly, how Cef::run() stops, and what happens at the end. What the documentation promises (docs/concepts.md,
// docs/message-loop.md) is checked here.
require __DIR__ . '/support.php';

use Cefloom\{BrowserHost, BrowserSettings, Cef, Client, LifeSpanHandler, LoadHandler, Settings, WindowInfo};

// ---- before CEF is started
same(false, Cef::isRunning(), 'CEF is not running at the start');
check(preg_match('/^\d+\.\d+\.\d+\+g[0-9a-f]+\+chromium-\d+\.\d+\.\d+\.\d+$/', Cef::cefVersion()) === 1, 'cefVersion() is a CEF version: ' . Cef::cefVersion());
$runtime = Cef::runtimeDirectory();
check(is_dir($runtime) && is_readable($runtime . '/libcef.so'), 'runtimeDirectory() holds the runtime');
$short = explode('+', Cef::cefVersion())[0];
check(str_ends_with(Cef::userRuntimeDirectory(), "/cefloom/runtime/$short"), 'userRuntimeDirectory() is <data>/cefloom/runtime/<CEF version>: ' . Cef::userRuntimeDirectory());

throws(fn() => Cef::doMessageLoopWork(), Error::class, 'the loop needs CEF to be running');
throws(fn() => Cef::run(), Error::class, 'run() needs CEF to be running');
throws(fn() => Cef::run('not a function'), TypeError::class, 'run() takes a callable or null');
throws(fn() => BrowserHost::createBrowser(new WindowInfo(), null, 'about:blank', new BrowserSettings(), null, null), Error::class, 'a browser needs CEF to be running');
Cef::shutdown();   // does nothing, and does not fail
same(false, Cef::isRunning(), 'shutdown() without CEF is harmless');

// custom schemes and switches are taken before the start
throws(fn() => Cef::registerCustomScheme(''), ValueError::class, 'an empty scheme name');
throws(fn() => Cef::registerCustomScheme('a:b'), ValueError::class, 'a scheme name with a colon');
throws(fn() => Cef::registerCustomScheme('a,b'), ValueError::class, 'a scheme name with a comma');
Cef::registerCustomScheme('twice');
Cef::registerCustomScheme('twice', 0);      // the second call replaces the options: no error
Cef::addSwitch('ozone-platform', 'x11');
Cef::addSwitch('disable-gpu');

// ---- a start that fails leaves nothing behind: the next start can succeed
$bad = new Settings();
$bad->browserSubprocessPath = '/no/such/cefloomsubprocess';
$bad->rootCachePath = tmpdir('cefloom-core-bad');
$message = '';
try {
    Cef::initialize($bad);
} catch (Error $e) {
    $message = $e->getMessage();
}
check(str_contains($message, 'was not found') && str_contains($message, '/no/such/cefloomsubprocess'), 'a missing subprocess executable is reported by name: ' . $message);
same(false, Cef::isRunning(), 'and CEF is not running after the failed start');

// ---- the real start; a path that the script set is kept, the others are filled in
$cache = tmpdir('cefloom-core-cache');
$settings = new Settings();
$settings->rootCachePath = $cache;
same(true, Cef::initialize($settings), 'initialize() starts CEF');
same(true, Cef::isRunning(), 'isRunning()');
throws(fn() => Cef::initialize(), Error::class, 'a second initialize() is an error');
throws(fn() => Cef::addSwitch('x'), Error::class, 'a switch after the start is an error');
throws(fn() => Cef::registerCustomScheme('late'), Error::class, 'a scheme after the start is an error');
check(count(glob("$cache/*")) > 0, 'CEF used the cache directory the script set (' . count(glob("$cache/*")) . ' entries)');

// ---- Cef::run() and how it stops
$ticks = 0;
Cef::run(function () use (&$ticks) { $ticks++; return false; });
same(1, $ticks, 'run() ends when $tick returns false, after one turn');

$ticks = 0;
Cef::run(function () use (&$ticks) { $ticks++; if ($ticks === 5) { Cef::quit(); } });
same(5, $ticks, 'Cef::quit() in $tick ends run()');

$ticks = 0;
Cef::run(function () use (&$ticks) { $ticks++; return $ticks < 3 ? null : false; }, quitWhenLastBrowserCloses: false);
same(3, $ticks, '$tick is called after every turn, and null or true go on');

$thrown = null;
try {
    Cef::run(function () { throw new RuntimeException('from the tick'); });
} catch (RuntimeException $e) {
    $thrown = $e->getMessage();
}
same('from the tick', $thrown, 'an exception of $tick comes out of run()');
$ticks = 0;
Cef::run(function () use (&$ticks) { $ticks++; return false; });
same(1, $ticks, 'and run() works again after it');

// the loop ends when the last browser closes (the default), and not before
final class Loads extends LoadHandler
{
    public int $loaded = 0;
    public function onLoadEnd($browser, $frame, $httpStatusCode): void
    {
        if ($frame->isMain()) {
            $this->loaded++;
            $browser->getHost()->closeBrowser(true);
        }
    }
}
final class Page extends Client
{
    public Loads $loads;
    public function __construct() { $this->loads = new Loads(); }
    public function getLoadHandler(): ?LoadHandler { return $this->loads; }
}
$page = new Page();
$info = new WindowInfo();
$info->bounds->width = 200;
$info->bounds->height = 150;
BrowserHost::createBrowser($info, $page, 'data:text/html,<title>t</title>', new BrowserSettings(), null, null);
$start = microtime(true);
Cef::run();
check($page->loads->loaded === 1 && microtime(true) - $start < 20, 'run() returns after the last browser has closed (loaded ' . $page->loads->loaded . ' time)');

// with quitWhenLastBrowserCloses off it goes on with no browser until $tick says stop
$ticks = 0;
Cef::run(function () use (&$ticks) { $ticks++; return $ticks < 20 ? null : false; }, false);
same(20, $ticks, 'quitWhenLastBrowserCloses: false keeps run() going without a browser');

// ---- Cef::wait()
$t = microtime(true);
Cef::wait(0);
check(microtime(true) - $t < 0.05, 'wait(0) returns at once');
if (!getenv('CEFLOOM_TEST_EXTERNAL_PUMP')) {
    $t = microtime(true);
    Cef::wait(2000);
    check(microtime(true) - $t < 0.3, 'in the polling mode wait() is cut to a few milliseconds (' . round((microtime(true) - $t) * 1000) . ' ms)');
}

// ---- the end
$kept = $page->loads;    // a PHP object of ours: stays usable
Cef::shutdown();
same(false, Cef::isRunning(), 'shutdown() stops CEF');
Cef::shutdown();         // twice: harmless
throws(fn() => Cef::doMessageLoopWork(), Error::class, 'the loop does not run after the shutdown');
throws(fn() => Cef::run(), Error::class, 'run() does not either');
same(1, $kept->loaded, 'the objects of the script outlive the shutdown');

// ---- what needs a process of its own
$script = tmpdir('cefloom-core-child') . '/child.php';
$prelude = "<?php\nrequire " . var_export(__DIR__ . '/support.php', true) . ";\nuse Cefloom\\{BrowserHost, BrowserSettings, Cef, Client, Settings, WindowInfo};\nCef::addSwitch('ozone-platform', 'x11'); Cef::addSwitch('disable-gpu');\n";
$env = ['XDG_CACHE_HOME' => tmpdir('cefloom-core-xdg')];

// CEF cannot be started again after it has been stopped
file_put_contents($script, $prelude . '
Cef::initialize(); Cef::shutdown();
try { Cef::initialize(); echo "started again\n"; Cef::shutdown(); } catch (Error $e) { echo "error: ", $e->getMessage(), "\n"; }
echo "done\n";');
[$code, $out] = run_php($script, [], $env, 60);
check($code === 0 && str_contains($out, 'error: ') && str_contains($out, 'done'), 'a second start after the shutdown is an error, not a crash (exit ' . $code . ': ' . substr(str_replace("\n", ' | ', $out), 0, 200) . ')');

// the default cache directory is under XDG_CACHE_HOME
file_put_contents($script, $prelude . 'Cef::initialize(); Cef::shutdown(); echo is_dir(getenv("XDG_CACHE_HOME") . "/cefloom") ? "cache ok\n" : "no cache\n";');
[$code, $out] = run_php($script, [], $env, 60);
check(str_contains($out, 'cache ok'), 'the default cache path is $XDG_CACHE_HOME/cefloom (' . substr(str_replace("\n", ' | ', $out), 0, 150) . ')');

// a script that ends without shutdown(): PHP's shutdown stops CEF
file_put_contents($script, $prelude . '
final class P extends Client {}
Cef::initialize();
$i = new WindowInfo(); $i->bounds->width = 200; $i->bounds->height = 150;
BrowserHost::createBrowser($i, new P(), "data:text/html,x", new BrowserSettings(), null, null);
$end = microtime(true) + 1.5; while (microtime(true) < $end) { Cef::doMessageLoopWork(); Cef::wait(5); }
echo "end of the script\n";');
$t = microtime(true);
[$code, $out] = run_php($script, [], $env, 60);
check($code === 0 && str_contains($out, 'end of the script'), 'a script that ends with a browser open ends normally (exit ' . $code . ': ' . substr(str_replace("\n", ' | ', $out), 0, 200) . ')');
check(microtime(true) - $t < 30, 'and does not hang at its end');

finish();
