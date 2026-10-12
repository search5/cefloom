<?php
// The App given to Cef::initialize() and its BrowserProcessHandler, the ResourceBundle (and its handler), ComponentUpdater, TaskManager
// and tracing.
require __DIR__ . '/support.php';

use Cefloom\{App, Browser, BrowserHost, BrowserProcessHandler, BrowserSettings, Cef, Client, CommandLine, ComponentUpdater, EndTracingCallback, LifeSpanHandler,
    ResourceBundle, ResourceBundleHandler, Settings, TaskManager, TaskInfo, WindowInfo};

final class Process extends BrowserProcessHandler
{
    public int $initialized = 0;
    public array $launches = [];
    public function onContextInitialized(): void { $this->initialized++; }
    public function onBeforeChildProcessLaunch(CommandLine $commandLine): void
    {
        $this->launches[] = $commandLine->getSwitchValue('type');
        $commandLine->appendSwitchWithValue('cefloom-marker', 'seen');
    }
}
final class Bundle extends ResourceBundleHandler
{
    public array $asked = [];
    public function getLocalizedString(int $stringId): array
    {
        $this->asked[] = $stringId;
        return [false, ''];         // [found, the string]: left to CEF
    }
}
final class MyApp extends App
{
    public array $lines = [];
    public Process $process;
    public Bundle $bundle;
    public function __construct() { $this->process = new Process(); $this->bundle = new Bundle(); }
    public function onBeforeCommandLineProcessing(string $processType, CommandLine $commandLine): void
    {
        $this->lines[] = [$processType, $commandLine->getProgram() !== ''];
        if ($processType === '') {
            $commandLine->appendSwitchWithValue('cefloom-test-switch', 'from-app');
        }
    }
    public function getBrowserProcessHandler(): ?BrowserProcessHandler { return $this->process; }
    public function getResourceBundleHandler(): ?ResourceBundleHandler { return $this->bundle; }
}
final class Life extends LifeSpanHandler
{
    public $browser = null;
    public function onAfterCreated($browser): void { $this->browser = $browser; }
}
final class Tracing extends EndTracingCallback
{
    public ?string $file = null;
    public function onEndTracingComplete(string $tracingFile): void { $this->file = $tracingFile; }
}
final class Page extends Client
{
    public Life $life;
    public function __construct() { $this->life = new Life(); }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
}

$base = start_http_server();
$app = new MyApp();
start_cef(new Settings(), $app);

// ---- the App
check($app->lines !== [] && $app->lines[0][0] === '', 'onBeforeCommandLineProcessing is called for the browser process (process type empty): ' . json_encode($app->lines));
wait_until(fn() => $app->process->initialized > 0, 'the context was initialized', 10000);
same(1, $app->process->initialized, 'onContextInitialized is called once');
$line = CommandLine::getGlobalCommandLine();
same('from-app', $line->getSwitchValue('cefloom-test-switch'), 'a switch added in onBeforeCommandLineProcessing is on the command line of the process');

// ---- a child process is launched when a browser needs a renderer
$page = new Page();
$info = new WindowInfo();
$info->bounds->width = 300;
$info->bounds->height = 200;
BrowserHost::createBrowser($info, $page, "$base/page?title=app", new BrowserSettings(), null, null);
wait_until(fn() => $page->life->browser !== null, 'the browser');
pump(1.5);
check(in_array('renderer', $app->process->launches, true), 'onBeforeChildProcessLaunch is called for the renderer: ' . json_encode($app->process->launches));
$browser = $page->life->browser;

// ---- the resource bundle
$bundle = ResourceBundle::getGlobal();
check($bundle !== null, 'there is a global resource bundle');
// ids from cef_pack_strings.h and cef_pack_resources.h (an id that is not in the packs ends the process inside CEF: K38)
const IDS_AX_IMAGE_ELIGIBLE_FOR_ANNOTATION = 45323;
const IDR_NET_ERROR_HTML = 39009;
$string = $bundle->getLocalizedString(IDS_AX_IMAGE_ELIGIBLE_FOR_ANNOTATION);
check($string !== '', 'getLocalizedString gives the string of the pack: ' . var_export($string, true));
$css = $bundle->getDataResource(IDR_NET_ERROR_HTML);
check($css !== null && $css->getSize() > 1000 && str_contains($css->getData(min(400, $css->getSize()), 0), '<'), 'getDataResource gives the data of a resource: ' . ($css ? $css->getSize() . ' bytes' : 'none'));
$scaled = $bundle->getDataResourceForScale(IDR_NET_ERROR_HTML, 1);
check($scaled === null || $scaled->getSize() > 0, 'getDataResourceForScale answers');

// ---- the component updater
$updater = ComponentUpdater::getComponentUpdater();
check($updater !== null, 'there is a component updater');
$count = $updater->getComponentCount();
same($count, count($updater->getComponents()), 'getComponentCount is the number of getComponents');
if ($count > 0) {
    $first = $updater->getComponents()[0];
    check($first->getID() !== '' && $first->getName() !== '', 'a component has an id and a name: ' . $first->getID());
    check($updater->getComponentByID($first->getID()) !== null, 'getComponentByID finds it');
}
same(null, $updater->getComponentByID('no-such-component-id'), 'and an unknown id: null');

// ---- the task manager
$manager = TaskManager::getTaskManager();
check($manager !== null, 'there is a task manager');
$ids = $manager->getTaskIdsList() ?? [];
check(count($ids) >= 1 && $manager->getTasksCount() === count($ids), 'it lists the tasks of the processes: ' . count($ids));
$taskInfo = $manager->getTaskInfo($ids[0], new TaskInfo());
check($taskInfo !== null && $taskInfo->title !== '', 'getTaskInfo gives the title of a task: ' . ($taskInfo->title ?? ''));
check($manager->getTaskIdForBrowserId($browser->getIdentifier()) !== 0, 'getTaskIdForBrowserId finds the task of the browser');
same(false, $manager->killTask(999999), 'killTask of an unknown task: false');

// ---- tracing
$traceFile = tmpdir('cefloom-trace') . '/trace.json';
check(Cef::beginTracing('', null), 'beginTracing');
$browser->getMainFrame()->loadURL("$base/hello");
pump(1.0);
$done = new Tracing();
check(Cef::endTracing($traceFile, $done), 'endTracing');
wait_until(fn() => $done->file !== null, 'the trace was written', 20000);
same($traceFile, $done->file, 'onEndTracingComplete gets the file');
check(is_file($traceFile) && filesize($traceFile) > 100 && json_decode((string) file_get_contents($traceFile)) !== null, 'and it is a JSON trace');

Cef::shutdown();
finish();
