<?php
// Input in the Views framework: the mouse and the keyboard reach the views and the page. The input is made with
// xdotool on the display of the test run (X's XTEST), so it travels the way a user's input does: X event, Chromium's
// window, Views, the button or the page. Skipped when xdotool is missing.
require __DIR__ . '/support.php';

use Cefloom\{BoxLayoutSettings, BrowserSettings, BrowserView, Button, ButtonDelegate, Cef, Client, DisplayHandler, LabelButton,
    LifeSpanHandler, LoadHandler, Panel, Rect, Window, WindowDelegate};

if (!can_make_input()) {
    echo "OK (0 checks) skipped: needs xdotool, a display and the polling message loop (with the external pump CEF does not read the X11 input of its own windows)\n";
    exit(0);
}

$GLOBALS['console'] = [];
$GLOBALS['pressed'] = [];

final class Press extends ButtonDelegate
{
    public function onButtonPressed(Button $button): void { $GLOBALS['pressed'][] = $button->getId(); }
}

final class Display extends DisplayHandler
{
    public function onConsoleMessage($browser, $level, $message, $source, $line): bool { $GLOBALS['console'][] = $message; return false; }
}

final class Load extends LoadHandler
{
    public bool $done = false;
    public function onLoadEnd($browser, $frame, $httpStatusCode): void { if ($frame->isMain()) { $this->done = true; } }
}

final class App extends Client
{
    public Load $load;
    private Display $display;
    public function __construct() { $this->load = new Load(); $this->display = new Display(); }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
    public function getDisplayHandler(): ?DisplayHandler { return $this->display; }
}

final class Frame extends WindowDelegate
{
    public ?LabelButton $button = null;
    public bool $shown = false;
    public function __construct(private BrowserView $browserView, private Press $press) {}
    public function onWindowCreated(Window $window): void
    {
        $strip = Panel::createPanel(null);
        $strip->setToBoxLayout(new BoxLayoutSettings(horizontal: 1));
        $this->button = LabelButton::createLabelButton($this->press, 'Press me');
        $this->button->setID(7);
        $strip->addChildView($this->button);
        $layout = $window->setToBoxLayout(new BoxLayoutSettings(horizontal: 0));
        $window->addChildView($strip);
        $window->addChildView($this->browserView);
        $layout->setFlexForView($this->browserView, 1);
        $window->show();
        $this->shown = true;
    }
    public function canClose(Window $window): bool { return true; }
    public function onWindowDestroyed(Window $window): void { $GLOBALS['console'][] = 'destroyed'; }
    public function getInitialBounds(Window $window): Rect { return new Rect(40, 40, 700, 500); }
}

$page = '<body style="margin:0"><input id="t" style="width:300px;height:30px;font:20px sans-serif" autofocus>'
    . '<div id="box" style="position:absolute;left:0;top:100px;width:300px;height:200px;background:#c33"></div>'
    . '<script>'
    . 'document.getElementById("t").addEventListener("input", e => console.log("input:" + e.target.value));'
    . 'document.getElementById("box").addEventListener("mousedown", e => console.log("mousedown:" + e.button));'
    . 'document.getElementById("box").addEventListener("wheel", e => console.log("wheel:" + (e.deltaY > 0 ? "down" : "up")));'
    . 'document.addEventListener("keydown", e => console.log("keydown:" + e.key));'
    . '</script></body>';

start_cef();
$app = new App();
$browserView = BrowserView::createBrowserView($app, 'data:text/html,' . rawurlencode($page), new BrowserSettings(), null, null, null);
$frame = new Frame($browserView, new Press());
$window = Window::createTopLevelWindow($frame);
wait_until(fn() => $frame->shown && $app->load->done, 'the window is shown and the page is loaded');
pump(1.0);   // let the layout settle

$button = $frame->button->getBoundsInScreen();
$view = $browserView->getBoundsInScreen();
check($button->width > 0 && $view->width > 0, "the views have screen bounds (button {$button->width}x{$button->height}, page {$view->width}x{$view->height})");

// ---- the mouse on a button of Views
xdo('mousemove', (string) ($button->x + intdiv($button->width, 2)), (string) ($button->y + intdiv($button->height, 2)));
pump(0.3);
xdo('click', '1');
wait_until(fn() => $GLOBALS['pressed'] !== [], 'a click on the button reached onButtonPressed', 5000);
same([7], $GLOBALS['pressed'], 'the button that was pressed is the one clicked');

// ---- the mouse in the page
xdo('mousemove', (string) ($view->x + 100), (string) ($view->y + 150));
pump(0.3);
xdo('click', '1');
wait_until(fn() => in_array('mousedown:0', $GLOBALS['console'], true), 'a click in the page reached the page', 5000);
check(in_array('mousedown:0', $GLOBALS['console'], true), 'the page saw the mouse button');
xdo('click', '5');   // wheel down
wait_until(fn() => in_array('wheel:down', $GLOBALS['console'], true), 'the wheel reached the page', 5000);
check(in_array('wheel:down', $GLOBALS['console'], true), 'the page saw the mouse wheel');

// ---- the keyboard in the page
xdo('mousemove', (string) ($view->x + 50), (string) ($view->y + 15));
pump(0.3);
xdo('click', '1');
pump(0.5);
xdo('type', '--delay', '60', 'cef');
wait_until(fn() => in_array('input:cef', $GLOBALS['console'], true), 'typed text reached the input of the page', 8000);
check(in_array('input:cef', $GLOBALS['console'], true), 'the input of the page holds what was typed (' . implode(',', array_filter($GLOBALS['console'], fn($m) => str_starts_with($m, 'input:'))) . ')');
check(in_array('keydown:c', $GLOBALS['console'], true), 'the page saw key events');

$window->close();
wait_until(fn() => in_array('destroyed', $GLOBALS['console'], true), 'the window closed', 15000);
unset($window, $frame, $browserView, $button, $view);
Cef::shutdown();
finish();
