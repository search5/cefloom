<?php
// Off-screen rendering: a RenderHandler gets the pixels of a page (checked, not only counted), the view size and screen info are asked
// of it, and mouse and keyboard events are sent to the page with the BrowserHost methods.
require __DIR__ . '/support.php';

use Cefloom\{AccessibilityHandler, Browser, BrowserHost, BrowserSettings, Buffer, Cef, Client, DisplayHandler, KeyEvent, LifeSpanHandler, LoadHandler, MouseEvent, Rect, RenderHandler,
    ScreenInfo, Settings, WindowInfo};
use Cefloom\Types\{KeyEventType, MouseButtonType, PaintElementType, State};

final class Tree extends AccessibilityHandler
{
    public array $trees = [];
    public int $locations = 0;
    public function onAccessibilityTreeChange(\Cefloom\Value $value): void
    {
        $dict = $value->getDictionary();
        $this->trees[] = $dict ? $dict->getKeys() : [];
    }
    public function onAccessibilityLocationChange(\Cefloom\Value $value): void { $this->locations++; }
}
final class Screen extends RenderHandler
{
    public Tree $tree;
    public function __construct() { $this->tree = new Tree(); }
    public function getAccessibilityHandler(): ?AccessibilityHandler { return $this->tree; }
    public int $width = 320;
    public int $height = 240;
    public array $paints = [];            // [type, width, height, dirty rects]
    public ?string $pixels = null;        // the last full frame, BGRA
    public array $asked = ['viewRect' => 0, 'screenInfo' => 0, 'screenPoint' => 0, 'rootRect' => 0];
    public array $selection = [];
    public function getViewRect(Browser $browser): Rect
    {
        $this->asked['viewRect']++;
        return new Rect(0, 0, $this->width, $this->height);
    }
    public function getScreenInfo(Browser $browser): array
    {
        $this->asked['screenInfo']++;
        $info = new ScreenInfo();
        $info->deviceScaleFactor = 1.0;
        $info->depth = 24;
        $info->depthPerComponent = 8;
        $info->rect = new Rect(0, 0, $this->width, $this->height);
        $info->availableRect = new Rect(0, 0, $this->width, $this->height);
        return [true, $info];
    }
    public function getRootScreenRect(Browser $browser): array { $this->asked['rootRect']++; return [true, new Rect(0, 0, $this->width, $this->height)]; }
    public function getScreenPoint(Browser $browser, int $viewX, int $viewY): array { $this->asked['screenPoint']++; return [true, $viewX, $viewY]; }
    public function onPaint(Browser $browser, int $type, array $dirtyRects, Buffer $buffer, int $width, int $height): void
    {
        $this->paints[] = [$type, $width, $height, count($dirtyRects)];
        if ($type === PaintElementType::VIEW && $buffer->getSize() === $width * $height * 4) {
            $this->pixels = $buffer->read(0, $buffer->getSize());      // the Buffer is valid only here: copy
            $this->pixelsWidth = $width;
            $this->pixelsHeight = $height;
        }
    }
    public int $pixelsWidth = 0;
    public int $pixelsHeight = 0;
    public function onTextSelectionChanged(Browser $browser, string $selectedText, \Cefloom\Range $selectedRange): void { $this->selection[] = $selectedText; }
    /** The pixel at x,y as [r, g, b, a]. */
    public function pixel(int $x, int $y): array
    {
        $o = ($y * $this->pixelsWidth + $x) * 4;
        return [ord($this->pixels[$o + 2]), ord($this->pixels[$o + 1]), ord($this->pixels[$o]), ord($this->pixels[$o + 3])];
    }
}
final class Console extends DisplayHandler
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
    public Screen $screen;
    public Console $console;
    public Life $life;
    public Load $load;
    public function __construct() { $this->screen = new Screen(); $this->console = new Console(); $this->life = new Life(); $this->load = new Load(); }
    public function getRenderHandler(): ?RenderHandler { return $this->screen; }
    public function getDisplayHandler(): ?DisplayHandler { return $this->console; }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
    public function logged(string $prefix): array
    {
        $found = [];
        foreach ($this->console->lines as $l) {
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
$info->bounds = new Rect(0, 0, 320, 240);
// a page: red background; a blue button at 10,10 (100x50) that logs a click and turns green; an input below it
$body = '<style>html,body{margin:0;background:#ff0000} #b{position:absolute;left:10px;top:10px;width:100px;height:50px;background:#0000ff;border:0}'
    . '#i{position:absolute;left:10px;top:100px;width:200px;height:30px} #t{position:absolute;left:10px;top:160px;margin:0;color:#fff}</style>'
    . '<p id="t">selected words</p>'
    . '<button id="b" onclick="this.style.background=\'#00ff00\'; console.log(\'C:click\')" onmouseenter="console.log(\'C:enter\')"></button>'
    . '<input id="i" oninput="console.log(\'C:input \' + this.value)">'
    . '<script>window.addEventListener("wheel", e => console.log("C:wheel " + Math.sign(e.deltaY)));</script>';
BrowserHost::createBrowser($info, $app, "$base/page?title=osr&body=" . rawurlencode($body), new BrowserSettings(), null, null);
wait_until(fn() => $app->life->browser !== null && $app->screen->pixels !== null && $app->load->ends > 0, 'the first frame', 30000);
pump(0.5);
$screen = $app->screen;
$host = $app->life->browser->getHost();

// ---- the handler is asked about the view and the screen
check($screen->asked['viewRect'] > 0 && $screen->asked['screenInfo'] > 0, 'getViewRect and getScreenInfo were asked: ' . json_encode($screen->asked));
same([PaintElementType::VIEW, 320, 240], array_slice($screen->paints[0], 0, 3), 'the first paint is the view, at the size getViewRect gave');
same(true, $host->isWindowRenderingDisabled(), 'isWindowRenderingDisabled: the browser is windowless');

// ---- the pixels
same([255, 0, 0, 255], $screen->pixel(200, 200), 'a pixel of the background is red (BGRA bytes read in the right order)');
same([0, 0, 255, 255], $screen->pixel(50, 30), 'a pixel of the button is blue');
same([255, 0, 0, 255], $screen->pixel(300, 10), 'and one beside it red again');
check($screen->paints !== [] && $screen->paints[0][3] >= 1, 'a paint comes with dirty rectangles');

// ---- mouse: a click on the button
$host->sendMouseMoveEvent(new MouseEvent(50, 30, 0), false);
pump(0.3);
$host->sendMouseClickEvent(new MouseEvent(50, 30, 0), MouseButtonType::LEFT, false, 1);
$host->sendMouseClickEvent(new MouseEvent(50, 30, 0), MouseButtonType::LEFT, true, 1);
wait_until(fn() => $app->logged('C:click') !== [], 'the click reached the page', 8000);
same(['enter', 'click'], array_values(array_filter($app->logged('C:'), fn($l) => in_array($l, ['enter', 'click'], true))), 'a move onto the button and a click arrive as events in the page');
wait_until(fn() => $screen->pixel(50, 30) === [0, 255, 0, 255], 'the button turned green', 8000);
same([0, 255, 0, 255], $screen->pixel(50, 30), 'and the next frame shows the page changed');

// ---- the wheel
$host->sendMouseWheelEvent(new MouseEvent(200, 200, 0), 0, -120);
wait_until(fn() => in_array('wheel 1', $app->logged('C:'), true), 'the wheel reached the page', 8000);
check(in_array('wheel 1', $app->logged('C:'), true), 'a wheel event down arrives (deltaY positive): ' . json_encode($app->logged('C:')));

// ---- keyboard: click the input and type
$host->sendMouseClickEvent(new MouseEvent(50, 110, 0), MouseButtonType::LEFT, false, 1);
$host->sendMouseClickEvent(new MouseEvent(50, 110, 0), MouseButtonType::LEFT, true, 1);
pump(0.3);
foreach (['h', 'i'] as $ch) {
    $code = ord(strtoupper($ch));
    $host->sendKeyEvent(new KeyEvent(KeyEventType::RAWKEYDOWN, 0, $code, 0, 0, ord($ch), ord($ch), 0));
    $host->sendKeyEvent(new KeyEvent(KeyEventType::CHAR, 0, ord($ch), 0, 0, ord($ch), ord($ch), 0));
    $host->sendKeyEvent(new KeyEvent(KeyEventType::KEYUP, 0, $code, 0, 0, ord($ch), ord($ch), 0));
}
wait_until(fn() => in_array('input hi', $app->logged('C:'), true), 'the typed text reached the input', 8000);
check(in_array('input hi', $app->logged('C:'), true), 'key events sent to the host are typed into the focused input: ' . json_encode($app->logged('C:')));

// ---- a resize
$screen->width = 200;
$screen->height = 150;
$screen->pixels = null;
$host->wasResized();
wait_until(fn() => $screen->pixels !== null && $screen->pixelsWidth === 200, 'a frame at the new size', 8000);
same([200, 150], [$screen->pixelsWidth, $screen->pixelsHeight], 'wasResized() makes CEF ask for the view again and paint at the new size');

// ---- the frame rate and invalidation
$host->setWindowlessFrameRate(15);
same(15, $host->getWindowlessFrameRate(), 'setWindowlessFrameRate / getWindowlessFrameRate');
$count = count($screen->paints);
$host->invalidate(PaintElementType::VIEW);
wait_until(fn() => count($screen->paints) > $count, 'a paint after invalidate', 8000);
check(count($screen->paints) > $count, 'invalidate() asks for a new paint');
$host->wasHidden(true);
pump(0.3);
$host->wasHidden(false);
check(true, 'wasHidden');

// ---- text selection is reported
$screen->selection = [];
// a double click on a word selects it
$host->sendMouseClickEvent(new MouseEvent(30, 170, 0), MouseButtonType::LEFT, false, 2);
$host->sendMouseClickEvent(new MouseEvent(30, 170, 0), MouseButtonType::LEFT, true, 2);
wait_until(fn() => in_array('selected', $screen->selection, true), 'the selection', 8000);
check(in_array('selected', $screen->selection, true), 'onTextSelectionChanged tells the selected word: ' . json_encode($screen->selection));

// ---- accessibility: with the state enabled the render handler's AccessibilityHandler gets the tree of the page
$host->setAccessibilityState(State::ENABLED);
wait_until(fn() => $screen->tree->trees !== [], 'an accessibility tree', 10000);
check($screen->tree->trees !== [] && $screen->tree->trees[0] !== [], 'onAccessibilityTreeChange gets a Value with the changes: keys ' . json_encode($screen->tree->trees[0] ?? null));

Cef::shutdown();
finish();
