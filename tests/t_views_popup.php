<?php
// A BrowserView: the JS bridge reaches PHP in it, a link with target=_blank reaches the delegate (getDelegateForPopupBrowserView,
// onPopupBrowserViewCreated), and Cef::shutdown() with the windows still open is clean (K42: it crashed). The click comes from
// xdotool: without it (or with the external message pump) only the bridge is tested.
require __DIR__ . '/support.php';

use Cefloom\{BrowserSettings, BrowserView, BrowserViewDelegate, Cef, Client, DisplayHandler, LifeSpanHandler, LoadHandler, Rect, Size, View, Window, WindowDelegate};

$log = [];
$consoleLines = [];
final class Life extends LifeSpanHandler
{
    public int $created = 0;
    public function onBeforePopup($browser, $frame, $popupId, $targetUrl, $targetFrameName, $targetDisposition, $userGesture, $popupFeatures): bool
    {
        $GLOBALS['log'][] = 'beforePopup:' . ($userGesture ? 'gesture' : 'none');
        return false;
    }
    public function onAfterCreated($browser): void { $this->created++; }
}
final class Display extends DisplayHandler
{
    public function onConsoleMessage($browser, $level, $message, $source, $line): bool { $GLOBALS['consoleLines'][] = $message; return false; }
}
final class Load extends LoadHandler
{
    public int $ends = 0;
    public function onLoadEnd($browser, $frame, $httpStatusCode): void { if ($frame->isMain()) { $this->ends++; } }
}
final class App extends Client
{
    public Life $life;
    public Load $load;
    public Display $display;
    public function __construct() { $this->life = new Life(); $this->load = new Load(); $this->display = new Display(); }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
    public function getDisplayHandler(): ?DisplayHandler { return $this->display; }
}
final class Popups extends BrowserViewDelegate
{
    public function getPreferredSize(View $view): Size { return new Size(400, 300); }
    public function getDelegateForPopupBrowserView(BrowserView $browserView, BrowserSettings $settings, ?Client $client, bool $isDevtools): ?BrowserViewDelegate
    {
        $GLOBALS['log'][] = $isDevtools ? 'delegate:devtools' : 'delegate:popup';
        return new Popups();
    }
    public function onPopupBrowserViewCreated(BrowserView $browserView, BrowserView $popupBrowserView, bool $isDevtools): bool
    {
        $GLOBALS['log'][] = 'popupCreated';
        return false;
    }
}
final class Windows extends WindowDelegate
{
    public ?Window $window = null;
    public function __construct(private View $content) {}
    public function onWindowCreated(Window $window): void { $this->window = $window; $window->addChildView($this->content); $window->show(); }
    public function getInitialBounds(Window $window): Rect { return new Rect(30, 30, 600, 400); }
}

final class Children extends WindowDelegate
{
    public ?Window $window = null;
    public function __construct(private Window $parent) {}
    public function onWindowCreated(Window $window): void { $this->window = $window; $window->show(); }
    public function getParentWindow(Window $window): array { $GLOBALS['log'][] = 'parent?'; return [$this->parent, false, false]; }
    public function isWindowModalDialog(Window $window): bool { $GLOBALS['log'][] = 'modal?'; return true; }
    public function getInitialBounds(Window $window): Rect { return new Rect(80, 80, 300, 200); }
}

$base = start_http_server();
start_cef();
Cef::expose('who', fn(string $tag): string => "php:$tag");
$app = new App();
$link = '<a id="l" href="/page?title=popup" target="_blank" style="display:block;width:560px;height:340px;background:#fa0">go</a>'
      . '<script>cefloom.call("who", "view").then(v => console.log("R:" + v))</script>';
$view = BrowserView::createBrowserView($app, "$base/page?title=view&body=" . rawurlencode($link), new BrowserSettings(), null, null, new Popups());
$windows = new Windows($view);
Window::createTopLevelWindow($windows);
wait_until(fn() => $windows->window !== null && $app->load->ends > 0, 'the window and its page');

wait_until(fn() => in_array('R:php:view', $GLOBALS['consoleLines'], true), 'the page called PHP');
check(in_array('R:php:view', $GLOBALS['consoleLines'], true), 'the JS bridge works in a BrowserView');

if (!can_make_input()) {
    echo "skipped the popup: no xdotool, no display or the external message pump\n";
    Cef::shutdown();
    finish();
}

pump(0.5);
$b = $view->getBoundsInScreen();
xdo('mousemove', (string) ($b->x + 100), (string) ($b->y + 100));
pump(0.3);
xdo('click', '1');
wait_until(fn() => in_array('popupCreated', $GLOBALS['log'], true), 'the popup is created', 10000);
check(in_array('beforePopup:gesture', $log, true), 'onBeforePopup comes first, with the user gesture: ' . json_encode($log));
check(in_array('delegate:popup', $log, true), 'getDelegateForPopupBrowserView is asked for the popup');
check(in_array('popupCreated', $log, true), 'onPopupBrowserViewCreated follows');
wait_until(fn() => $app->life->created >= 2, 'the client sees the popup browser too');
check($app->life->created >= 2, 'the client of the view gets onAfterCreated for the popup');

// ---- a window with a parent: getParentWindow, and isWindowModalDialog which is asked only then
// (acceptsFirstMouse and withStandardWindowButtons are for macOS only, so they never come on Linux)
$children = new Children($windows->window);
Window::createTopLevelWindow($children);
wait_until(fn() => in_array('modal?', $GLOBALS['log'], true), 'the child window asks whether it is modal', 10000);
check(in_array('parent?', $log, true), 'getParentWindow is asked when a window is made');
check(in_array('modal?', $log, true), 'isWindowModalDialog is asked once there is a parent window: ' . json_encode($log));
$children->window?->close();
pump(0.3);

// ---- the windows stay open: shutdown closes their browsers
Cef::shutdown();
same(false, Cef::isRunning(), 'Cef::shutdown() with the view and its popup still open is clean');
finish();
