<?php
// What happens to an exception, an exit() or a wrong value in a handler (docs/concepts.md, "Errors"): it never travels into
// CEF. It stays pending, the callbacks that follow do not run PHP, and PHP throws it when the function that entered CEF
// returns: Cef::run(), Cef::doMessageLoopWork(), or a method of a library class.
require __DIR__ . '/support.php';

use Cefloom\{BrowserHost, BrowserSettings, Cef, Client, DisplayHandler, LoadHandler, Task, WindowInfo};
use Cefloom\Types\ThreadId;

final class Boom extends Task
{
    public function __construct(private string $message, private string $class = RuntimeException::class) {}
    public function execute(): void { throw new ($this->class)($this->message); }
}
final class Count extends Task
{
    public static int $n = 0;
    public function execute(): void { self::$n++; }
}

start_cef();

// ---- doMessageLoopWork() throws what a handler left pending, and the callbacks after it do not run PHP
Count::$n = 0;
Cef::postTask(ThreadId::UI, new Boom('first'));
Cef::postTask(ThreadId::UI, new Count());      // runs in the same turn, after the exception
$caught = null;
try {
    Cef::doMessageLoopWork();
} catch (RuntimeException $e) {
    $caught = $e->getMessage();
}
same('first', $caught, 'doMessageLoopWork() throws the exception that a task left pending');
same(0, Count::$n, 'a task that follows in the same turn did not run PHP');
Cef::postTask(ThreadId::UI, new Count());
Cef::doMessageLoopWork();
pump(0.1);
same(1, Count::$n, 'afterwards tasks run again');

// the kind of the exception is kept (an Error, a user exception)
foreach ([TypeError::class, LogicException::class, ErrorException::class] as $class) {
    Cef::postTask(ThreadId::UI, new Boom('x', $class));
    $got = null;
    try {
        Cef::doMessageLoopWork();
    } catch (Throwable $e) {
        $got = get_class($e);
    }
    same($class, $got, "the class of the exception is kept ($class)");
}

// a handler of a browser: an exception in onLoadEnd comes out of Cef::run(), and run() can be called again
final class Throwing extends LoadHandler
{
    public int $calls = 0;
    public function onLoadEnd($browser, $frame, $httpStatusCode): void
    {
        if ($frame->isMain()) {
            $this->calls++;
            throw new DomainException('from onLoadEnd');
        }
    }
}
final class Console extends DisplayHandler
{
    public array $messages = [];
    public function onConsoleMessage($browser, $level, $message, $source, $line): bool
    {
        $this->messages[] = $message;
        return $message === 'quiet';        // true: CEF does not log it
    }
}
final class App extends Client
{
    public LoadHandler $load;
    public Console $console;
    public function __construct() { $this->load = new Throwing(); $this->console = new Console(); }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
    public function getDisplayHandler(): ?DisplayHandler { return $this->console; }
}
$app = new App();
$info = new WindowInfo();
$info->bounds->width = 200;
$info->bounds->height = 150;
BrowserHost::createBrowser($info, $app, 'data:text/html,' . rawurlencode('<script>console.log("quiet"); console.log("loud");</script>'), new BrowserSettings(), null, null);
$caught = null;
$start = microtime(true);
try {
    Cef::run();
} catch (DomainException $e) {
    $caught = $e->getMessage();
}
same('from onLoadEnd', $caught, 'run() throws the exception of a handler');
check(microtime(true) - $start < 20, 'and ends the loop');
same(true, Cef::isRunning(), 'CEF goes on running');
$throwing = $app->load;
same(1, $throwing->calls, 'the handler was called once');

// the loop works again; the console handler got its messages (the value it returned for CEF is any value)
$app->load = new class extends LoadHandler {};   // a handler of CEF that does nothing
$start = microtime(true);
while (count($app->console->messages) < 2 && microtime(true) - $start < 10) {
    Cef::doMessageLoopWork();
    Cef::wait(5);
}
same(['quiet', 'loud'], $app->console->messages, 'after the exception the handlers go on (the console messages arrived)');

// PHP does not accept a handler whose parameters clash with the declaration of its base class: the arginfo of the handlers
// carries the types, so this is found when the class is declared, not when CEF calls it.
// (checked in a process of its own below: PHP ends the script with a fatal error)

// a handler that returns something that is not what CEF asks for (it declares no return type): the value is converted
final class LooseConsole extends DisplayHandler
{
    public int $calls = 0;
    #[\ReturnTypeWillChange]
    public function onConsoleMessage($browser, $level, $message, $source, $line)
    {
        $this->calls++;
        return ['not', 'a', 'bool'];
    }
}
final class LooseApp extends Client
{
    public LooseConsole $console;
    public function __construct() { $this->console = new LooseConsole(); }
    public function getDisplayHandler(): ?DisplayHandler { return $this->console; }
}
$loose = new LooseApp();
BrowserHost::createBrowser($info, $loose, 'data:text/html,' . rawurlencode('<script>console.log("a"); console.log("b");</script>'), new BrowserSettings(), null, null);
$end = microtime(true) + 10;
$error = null;
try {
    while (microtime(true) < $end && $loose->console->calls < 2) {
        Cef::doMessageLoopWork();
        Cef::wait(5);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
same(null, $error, 'a value of the wrong kind from a handler is not an error');
same(2, $loose->console->calls, 'and the handler goes on being called');

Cef::shutdown();

// ---- what needs a process of its own: exit() and an uncaught exception in a handler
$script = tmpdir('cefloom-exceptions') . '/child.php';
$prelude = "<?php\nrequire " . var_export(__DIR__ . '/support.php', true) . ";\nuse Cefloom\\{BrowserHost, BrowserSettings, Cef, Client, LoadHandler, WindowInfo};\nCef::addSwitch('ozone-platform', 'x11'); Cef::addSwitch('disable-gpu');\n";
$body = '
final class H extends LoadHandler { function onLoadEnd($b, $f, $c): void { if ($f->isMain()) { %s } } }
final class A extends Client { private H $h; function __construct() { $this->h = new H; } function getLoadHandler(): ?LoadHandler { return $this->h; } }
Cef::initialize();
$i = new WindowInfo(); $i->bounds->width = 200; $i->bounds->height = 150;
BrowserHost::createBrowser($i, new A, "data:text/html,x", new BrowserSettings(), null, null);
Cef::run();
echo "after run\n";
Cef::shutdown();
echo "after shutdown\n";';
$env = ['XDG_CACHE_HOME' => tmpdir('cefloom-exceptions-xdg')];

file_put_contents($script, $prelude . sprintf($body, 'echo "exiting\n"; exit(3);'));
[$code, $out] = run_php($script, [], $env, 60);
check($code === 3 && str_contains($out, 'exiting') && !str_contains($out, 'after run'), "exit() in a handler ends the script with its code, and CEF is stopped (exit $code: " . substr(str_replace("\n", ' | ', $out), 0, 160) . ')');

file_put_contents($script, $prelude . sprintf($body, 'throw new RuntimeException("uncaught in a handler");'));
[$code, $out] = run_php($script, [], $env, 60);
check($code === 255 && str_contains($out, 'Uncaught RuntimeException: uncaught in a handler') && !str_contains($out, 'after run'),
    "an uncaught exception of a handler ends the script as PHP does, without a crash (exit $code: " . substr(str_replace("\n", ' | ', $out), 0, 200) . ')');

// a handler with a clashing parameter type is refused by PHP when the class is declared (a fatal error, so: its own process)
file_put_contents($script, "<?php\nfinal class ClashingHandler extends Cefloom\\LoadHandler { public function onLoadEnd(int \$browser, \$frame, \$httpStatusCode): void {} }\necho 'declared';");
[$code, $out] = run_php($script, [], $env, 30);
check($code === 255 && str_contains($out, 'must be compatible with Cefloom\\LoadHandler::onLoadEnd(Cefloom\\Browser $browser'), 'a handler whose parameter type clashes with the base class is refused at its declaration (' . substr(str_replace("\n", ' | ', $out), 0, 120) . ')');

finish();
