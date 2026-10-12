<?php
// BrowserHost and Frame calls that the other tests leave out, on an off-screen browser (no window is needed): editing commands of a frame,
// the IME, touch events, drag and drop onto the page, external begin frames, auto resize, DevTools messages, Chrome commands, spelling,
// downloads started by URL, the file dialog, and the entries of the history. The page logs what it received.
require __DIR__ . '/support.php';

use Cefloom\{Browser, BrowserHost, BrowserSettings, Buffer, Cef, Client, CompositionUnderline, DisplayHandler, DragData, LifeSpanHandler, LoadHandler, MouseEvent, ProcessMessage,
    Range, Rect, RenderHandler, RunFileDialogCallback, ScreenInfo, Settings, Size, TouchEvent, WindowInfo};
use Cefloom\Types\{DragOperationsMask, FileDialogMode, PointerType, ProcessId, TouchEventType};

final class Screen extends RenderHandler
{
    public int $paints = 0;
    public array $drag = [];
    public function getViewRect(Browser $browser): Rect { return new Rect(0, 0, 400, 300); }
    public function getScreenInfo(Browser $browser): array
    {
        $i = new ScreenInfo();
        $i->deviceScaleFactor = 1.0; $i->depth = 24; $i->depthPerComponent = 8;
        $i->rect = new Rect(0, 0, 400, 300); $i->availableRect = new Rect(0, 0, 400, 300);
        return [true, $i];
    }
    public function onPaint(Browser $browser, int $type, array $dirtyRects, Buffer $buffer, int $width, int $height): void { $this->paints++; }
    public function onImeCompositionRangeChanged(Browser $browser, Range $selectedRange, array $characterBounds): void { $this->drag[] = 'ime-range'; }
    public function updateDragCursor(Browser $browser, int $operation): void { $this->drag[] = "cursor:$operation"; }
    public function onScrollOffsetChanged(Browser $browser, float $x, float $y): void { $this->drag[] = 'scroll'; }
}
final class Display extends DisplayHandler
{
    public array $lines = [];
    public array $resized = [];
    public function onConsoleMessage($browser, $level, $message, $source, $line): bool { $this->lines[] = $message; return false; }
    public function onAutoResize(Browser $browser, Size $newSize): bool { $this->resized[] = [$newSize->width, $newSize->height]; return true; }
}
final class Dialog extends RunFileDialogCallback
{
    public ?array $paths = null;
    public function onFileDialogDismissed(array $filePaths): void { $this->paths = $filePaths; }
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
    public Screen $screen;
    public Display $display;
    public Life $life;
    public Load $load;
    public function __construct() { $this->screen = new Screen(); $this->display = new Display(); $this->life = new Life(); $this->load = new Load(); }
    public function getRenderHandler(): ?RenderHandler { return $this->screen; }
    public function getDisplayHandler(): ?DisplayHandler { return $this->display; }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
    public function logged(string $prefix): array
    {
        $found = [];
        foreach ($this->display->lines as $l) {
            if (str_starts_with($l, $prefix)) { $found[] = substr($l, strlen($prefix)); }
        }
        return $found;
    }
}

$base = start_http_server();
$settings = new Settings();
$settings->windowlessRenderingEnabled = 1;
start_cef($settings);
$app = new App();
$info = new WindowInfo();
$info->windowlessRenderingEnabled = 1;
$info->bounds = new Rect(0, 0, 400, 300);
$body = '<input id="i" value="start" style="position:absolute;left:10px;top:10px;width:300px;height:30px">'
    . '<div id="drop" style="position:absolute;left:10px;top:100px;width:300px;height:150px;background:#ddd">drop here</div>'
    . '<script>'
    . 'const log = m => console.log("P:" + m);'
    . 'document.getElementById("drop").addEventListener("dragover", e => { e.preventDefault(); });'
    . 'document.getElementById("drop").addEventListener("drop", e => { e.preventDefault(); log("drop " + e.dataTransfer.getData("text/plain")); });'
    . 'document.getElementById("i").addEventListener("compositionupdate", e => log("composition " + e.data));'
    . 'document.getElementById("i").addEventListener("compositionend", e => log("compositionend " + e.data));'
    . 'document.addEventListener("touchstart", e => log("touchstart"), {passive: true});'
    . 'document.addEventListener("pointerdown", e => log("pointerdown " + e.pointerType));'
    . '</script>';
BrowserHost::createBrowser($info, $app, "$base/page?title=host&body=" . rawurlencode($body), new BrowserSettings(), null, null);
wait_until(fn() => $app->life->browser !== null && $app->load->ends > 0 && $app->screen->paints > 0, 'the page');
pump(0.5);
$browser = $app->life->browser;
$host = $browser->getHost();
$frame = $browser->getMainFrame();

// ---- the frame's editing commands (the input is focused by a click)
$host->setFocus(true);
$host->sendMouseClickEvent(new MouseEvent(100, 25, 0), \Cefloom\Types\MouseButtonType::LEFT, false, 1);
$host->sendMouseClickEvent(new MouseEvent(100, 25, 0), \Cefloom\Types\MouseButtonType::LEFT, true, 1);
pump(0.3);
$frame->selectAll();
$frame->copy();
$frame->cut();
pump(0.3);
$frame->executeJavaScript("console.log('P:value ' + JSON.stringify(document.getElementById('i').value))", '', 0);
wait_until(fn() => in_array('value ""', $app->logged('P:'), true), 'cut emptied the input', 8000);
check(in_array('value ""', $app->logged('P:'), true), 'selectAll + cut empties the input');
check(is_bool($frame->isFocused()), 'isFocused');
$message = ProcessMessage::create('hello-renderer');
$message->getArgumentList()->setString(0, 'x');
$frame->sendProcessMessage(ProcessId::RENDERER, $message);
check(true, 'sendProcessMessage to the renderer is accepted');
[$request, $fetcher] = [Cefloom\Request::create(), new Fetcher()];
$request->setURL("$base/hello");
$request->setMethod('GET');
$urlRequest = $frame->createURLRequest($request, $fetcher);
wait_until(fn() => $fetcher->done, 'a URL request made by the frame', 20000);
same([\Cefloom\Types\URLRequestStatus::SUCCESS, 'hello'], [$fetcher->status, $fetcher->body], 'Frame::createURLRequest fetches a URL in the context of the frame');

// ---- the IME
$host->imeSetComposition('한', [new CompositionUnderline()], new Range(0, 0), new Range(1, 1));
pump(0.3);
$host->imeCommitText('한글', new Range(0, 0), 0);
pump(0.3);
$host->imeSetComposition('가', [], new Range(0, 0), new Range(1, 1));
$host->imeFinishComposingText(false);
$host->imeSetComposition('나', [], new Range(0, 0), new Range(1, 1));
$host->imeCancelComposition();
wait_until(fn() => array_filter($app->logged('P:'), fn($l) => str_starts_with($l, 'composition')) !== [], 'composition events', 8000);
check(array_filter($app->logged('P:'), fn($l) => str_starts_with($l, 'composition')) !== [], 'IME composition events reach the page: ' . json_encode($app->logged('P:')));

// ---- touch
$host->sendTouchEvent(new TouchEvent(1, 50.0, 50.0, 5.0, 5.0, 0.0, 1.0, TouchEventType::PRESSED, 0, PointerType::TOUCH));
$host->sendTouchEvent(new TouchEvent(1, 52.0, 52.0, 5.0, 5.0, 0.0, 1.0, TouchEventType::MOVED, 0, PointerType::TOUCH));
$host->sendTouchEvent(new TouchEvent(1, 52.0, 52.0, 5.0, 5.0, 0.0, 1.0, TouchEventType::RELEASED, 0, PointerType::TOUCH));
pump(0.5);
check(array_filter($app->logged('P:'), fn($l) => str_starts_with($l, 'pointerdown')) !== [], 'a touch event arrives in the page as a pointer event: ' . json_encode(array_slice($app->logged('P:'), -4)));

// ---- drag and drop onto the page
$drag = DragData::create();
$drag->setFragmentText('dropped text');
$ops = DragOperationsMask::COPY | DragOperationsMask::MOVE;
$host->dragTargetDragEnter($drag, new MouseEvent(100, 150, 0), $ops);
pump(0.3);
$host->dragTargetDragOver(new MouseEvent(110, 160, 0), $ops);
pump(0.3);
$host->dragTargetDragOver(new MouseEvent(112, 162, 0), $ops);
pump(0.3);
$host->dragTargetDrop(new MouseEvent(112, 162, 0));
wait_until(fn() => in_array('drop dropped text', $app->logged('P:'), true), 'the drop', 5000);
$host->dragSourceEndedAt(110, 160, DragOperationsMask::COPY);
$host->dragSourceSystemDragEnded();
$host->dragTargetDragEnter($drag, new MouseEvent(100, 150, 0), $ops);
$host->dragTargetDragLeave();
pump(0.5);
check(in_array('drop dropped text', $app->logged('P:'), true), 'dragTargetDragEnter / Over / Drop deliver a drop with the dragged text to the page: ' . json_encode($app->logged('P:')));

// ---- begin frames, screen info, capture lost, resize notifications
$host->sendExternalBeginFrame();
$host->notifyScreenInfoChanged();
$host->notifyMoveOrResizeStarted();
$host->sendCaptureLostEvent();
$host->setAxViewportCollapse(true);
$host->setAxViewportCollapse(false);
check(true, 'sendExternalBeginFrame, notifyScreenInfoChanged, notifyMoveOrResizeStarted, sendCaptureLostEvent and setAxViewportCollapse are accepted');

// ---- auto resize
$host->setAutoResizeEnabled(true, new Size(100, 100), new Size(600, 500));
$frame->executeJavaScript("document.body.style.minHeight = '250px'", '', 0);
pump(1.0);
$host->setAutoResizeEnabled(false, new Size(0, 0), new Size(0, 0));
check(true, 'setAutoResizeEnabled is accepted (' . count($app->display->resized) . ' onAutoResize calls)');

// ---- DevTools messages as text, and a window of DevTools
$host->sendDevToolsMessage('{"id":1,"method":"Runtime.evaluate","params":{"expression":"1+1"}}');
check($host->hasDevTools() === false, 'no DevTools window is open');
$host->closeDevTools();

// ---- Chrome commands
check(is_bool($host->canExecuteChromeCommand(33000)), 'canExecuteChromeCommand answers');
$host->executeChromeCommand(33000, 0);

// ---- spelling
$host->addWordToDictionary('cefloom');
$host->replaceMisspelling('word');
check(true, 'addWordToDictionary and replaceMisspelling are accepted');

// ---- the file dialog, the context, opener information
$dialog = new Dialog();
$host->runFileDialog(FileDialogMode::OPEN, 'Pick', '', ['.txt'], $dialog);
pump(1.0);
check($host->getRequestContext() !== null && is_bool($host->hasView()) && is_int($host->getOpenerWindowHandle()), 'getRequestContext, hasView, getOpenerWindowHandle');
same(false, $host->isFullscreen(), 'not fullscreen');
$host->exitFullscreen(false);

// ---- the history entries
$entry = $host->getVisibleNavigationEntry();
check(str_contains($entry->getURL(), 'title=host') && $entry->getDisplayURL() !== '' && $entry->getOriginalURL() !== '', 'NavigationEntry: URL, display URL and original URL');
check(is_int($entry->getTransitionType()) && is_bool($entry->hasPostData()) && ($entry->getCompletionTime() === null || $entry->getCompletionTime() instanceof DateTimeImmutable), 'transition type, post data and completion time');

// ---- a download started by URL (the browser has no DownloadHandler: the request goes on in CEF)
$host->startDownload("$base/download?size=100");
pump(0.5);
check(true, 'startDownload is accepted');

// last: these make the page read the clipboard, and in the event-driven loop that stalls the input that follows (K41)
$frame->undo();
$frame->paste();
$frame->redo();
$frame->pasteAndMatchStyle();
$frame->delete();
check(true, 'undo, paste, redo, pasteAndMatchStyle and delete are accepted');
$frame->viewSource();      // opens another browser
pump(0.5);
$host->closeBrowser(true);
pump(0.5);
Cef::shutdown();
finish();
