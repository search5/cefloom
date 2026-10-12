<?php
// The Views framework: a top level window made by CEF with a BrowserView inside, driven by delegates; plus a
// menu model and a button, which are made without a window.
require __DIR__ . '/support.php';

use Cefloom\{BrowserSettings, BrowserView, BrowserViewDelegate, ButtonDelegate, Cef, Client, LabelButton, LifeSpanHandler,
    LoadHandler, MenuModel, MenuModelDelegate, Window, WindowDelegate};

$GLOBALS['events'] = [];

final class Delegate extends WindowDelegate
{
    public ?Window $window = null;
    public function __construct(private BrowserView $browserView) {}
    public function onWindowCreated(Window $window): void
    {
        $GLOBALS['events'][] = 'created';
        $this->window = $window;
        $window->setTitle('cefloom views');
        $window->addChildView($this->browserView);
        $window->show();
    }
    public function onWindowActivationChanged(Window $window, bool $active): void { $GLOBALS['events'][] = $active ? 'activated' : 'deactivated'; }
    public function onWindowDestroyed(Window $window): void { $GLOBALS['events'][] = 'destroyed'; $this->window = null; }
    public function canClose(Window $window): bool { $GLOBALS['events'][] = 'canClose'; return true; }
    public function getInitialBounds(Window $window): \Cefloom\Rect
    {
        $r = new \Cefloom\Rect();
        $r->x = 50; $r->y = 50; $r->width = 640; $r->height = 480;
        return $r;
    }
}

final class Load extends LoadHandler
{
    public bool $done = false;
    public function onLoadEnd($browser, $frame, $httpStatusCode): void { if ($frame->isMain()) { $this->done = true; } }
}

final class App extends Client
{
    public Load $load;
    public function __construct() { $this->load = new Load(); }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
}

final class Menu extends MenuModelDelegate
{
    public array $executed = [];
    public function executeCommand(MenuModel $menuModel, int $commandId, int $eventFlags): void { $this->executed[] = $commandId; }
}

final class Buttons extends ButtonDelegate
{
    public function onButtonPressed(\Cefloom\Button $button): void {}
}

start_cef();

// ---- objects that need no window
$menuDelegate = new Menu();
$menu = MenuModel::create($menuDelegate);
check($menu !== null, 'a menu model is made');
check($menu->addItem(1, 'First'), 'addItem');
check($menu->addSeparator(), 'addSeparator');
check($menu->addCheckItem(2, 'Second'), 'addCheckItem');
same(3, $menu->getCount(), 'the menu has three entries');
same('First', $menu->getLabel(1), 'label of a command');
same(true, $menu->isEnabled(1), 'enabled by default');
$menu->setEnabled(1, false);
same(false, $menu->isEnabled(1), 'setEnabled');
$button = LabelButton::createLabelButton(new Buttons(), 'Press');
check($button !== null, 'a label button is made');
same('Press', $button->getText(), 'button text');

// ---- the window
$app = new App();
$browserView = BrowserView::createBrowserView($app, 'data:text/html,' . rawurlencode('<title>v</title><h1>views</h1>'), new BrowserSettings(), null, null, null);
check($browserView !== null, 'a browser view is made');
$delegate = new Delegate($browserView);
$window = Window::createTopLevelWindow($delegate);
check($window !== null, 'a top level window is made');
wait_until(fn() => in_array('created', $GLOBALS['events'], true), 'onWindowCreated');
wait_until(fn() => $app->load->done, 'the page loaded inside the window');
check($window->isVisible(), 'the window is visible');
same('cefloom views', $window->getTitle(), 'the title');
$bounds = $window->getBounds();
check($bounds->width > 0 && $bounds->height > 0, "the window has a size ({$bounds->width}x{$bounds->height})");
check($browserView->getBrowser() !== null, 'the browser view holds a browser');

$window->close();
wait_until(fn() => in_array('destroyed', $GLOBALS['events'], true), 'onWindowDestroyed', 15000);
check(in_array('canClose', $GLOBALS['events'], true), 'canClose was asked');

unset($window, $delegate, $browserView, $button, $menu);
Cef::shutdown();
finish();
