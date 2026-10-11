<?php
// Tasks: Cef::postTask / postDelayedTask on several CEF threads run PHP on the main thread.
require __DIR__ . '/support.php';

use Cefloom\{Cef, Task};
use Cefloom\Types\ThreadId;

final class Probe extends Task
{
    public static array $log = [];
    public function __construct(private string $tag, private ?int $thread = null) {}
    public function execute(): void
    {
        self::$log[] = [$this->tag, Cef::currentlyOn(ThreadId::UI), getmypid()];
    }
}

final class Count extends Task
{
    public static int $n = 0;
    public static int $onUi = 0;
    public function execute(): void
    {
        self::$n++;
        if (Cef::currentlyOn(ThreadId::UI)) { self::$onUi++; }
    }
}

final class Chain extends Task
{
    public static array $order = [];
    public function __construct(private int $depth) {}
    public function execute(): void
    {
        self::$order[] = $this->depth;
        if ($this->depth < 5) {
            Cef::postTask(ThreadId::UI, new Chain($this->depth + 1));
        }
    }
}

start_cef();

same(true, Cef::currentlyOn(ThreadId::UI), 'PHP main thread is the UI thread');
same(false, Cef::currentlyOn(ThreadId::IO), 'main thread is not IO');

// UI thread
same(true, Cef::postTask(ThreadId::UI, new Probe('ui')), 'postTask UI returns true');
wait_until(fn() => count(Probe::$log) >= 1, 'UI task ran');
same('ui', Probe::$log[0][0], 'task tag');
same(true, Probe::$log[0][1], 'UI task executes on the UI (PHP main) thread');

// Other threads: the task must still run PHP on the main thread
foreach (['IO' => ThreadId::IO, 'FILE_USER_VISIBLE' => ThreadId::FILE_USER_VISIBLE,
          'FILE_BACKGROUND' => ThreadId::FILE_BACKGROUND, 'FILE_USER_BLOCKING' => ThreadId::FILE_USER_BLOCKING] as $name => $id) {
    Probe::$log = [];
    same(true, Cef::postTask($id, new Probe($name)), "postTask $name returns true");
    wait_until(fn() => count(Probe::$log) >= 1, "$name task ran");
    same($name, Probe::$log[0][0] ?? null, "$name task tag");
    same(true, Probe::$log[0][1] ?? null, "$name task: PHP runs on the main thread (UI)");
}

// Delayed
Probe::$log = [];
$t0 = microtime(true);
same(true, Cef::postDelayedTask(ThreadId::UI, new Probe('delayed'), 300), 'postDelayedTask returns true');
for ($i = 0; $i < 5; $i++) { Cef::doMessageLoopWork(); usleep(10000); }
same(0, count(Probe::$log), 'delayed task has not run yet after 50 ms');
wait_until(fn() => count(Probe::$log) >= 1, 'delayed task ran');
$dt = microtime(true) - $t0;
check($dt >= 0.28, "delayed task waited the delay (took $dt)");
check($dt < 3.0, "delayed task not excessively late ($dt)");

// Delayed task on IO thread
Probe::$log = [];
Cef::postDelayedTask(ThreadId::IO, new Probe('io-delayed'), 50);
wait_until(fn() => count(Probe::$log) >= 1, 'delayed IO task ran');
same(true, Probe::$log[0][1] ?? null, 'delayed IO task runs PHP on main thread');

// Many tasks at once from several CEF threads
Count::$n = 0; Count::$onUi = 0;
$threads = [ThreadId::UI, ThreadId::IO, ThreadId::FILE_USER_VISIBLE, ThreadId::FILE_BACKGROUND];
for ($i = 0; $i < 400; $i++) {
    Cef::postTask($threads[$i % 4], new Count());
}
wait_until(fn() => Count::$n >= 400, 'all 400 tasks ran', 30000);
same(400, Count::$n, '400 tasks executed exactly once');
same(400, Count::$onUi, 'all 400 ran on the UI thread');

// A task posting a task
Cef::postTask(ThreadId::UI, new Chain(0));
wait_until(fn() => count(Chain::$order) >= 6, 'chain finished');
same([0, 1, 2, 3, 4, 5], Chain::$order, 'chained tasks ran in order');

// A task that is freed on the PHP side right after posting still runs (CEF holds it)
Count::$n = 0;
(function () { Cef::postTask(ThreadId::IO, new Count()); })();
wait_until(fn() => Count::$n >= 1, 'task owned only by CEF ran');
same(1, Count::$n, 'task without PHP reference ran once');

// Argument validation
throws(fn() => Cef::postTask(ThreadId::UI, 'nope'), TypeError::class, 'postTask requires a Task');
throws(fn() => Cef::postTask('x', new Count()), TypeError::class, 'postTask requires an int thread id');

Cef::shutdown();
same(false, Cef::isRunning(), 'stopped');
finish();
