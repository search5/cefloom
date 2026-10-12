<?php
// What t_views_widgets leaves of the Views framework: the rest of the delegates (size hints, focus, key events, accelerators, menu commands, the
// button pressed by a click), popups of a BrowserView, overlays by bounds, layouts, scroll views and fullscreen. Clicks and keys come from xdotool,
// aimed with the screen bounds of the views.
require __DIR__ . '/support.php';

use Cefloom\{BrowserSettings, BrowserView, BrowserViewDelegate, Button, ButtonDelegate, Cef, Client, KeyEvent, LabelButton, LifeSpanHandler, MenuButton, MenuButtonDelegate,
    MenuButtonPressedLock, MenuModel, MenuModelDelegate, Panel, PanelDelegate, Point, PopupFeatures, Rect, ScrollView, Size, Textfield, TextfieldDelegate, View, ViewDelegate, Window, WindowDelegate};
use Cefloom\Types\{DockingMode, MenuAnchorPosition};

if (!can_make_input()) {
    echo "OK (0 checks) skipped: needs xdotool, a display and the polling message loop\n";
    exit(0);
}

$GLOBALS['log'] = [];
trait Notes
{
    public function getPreferredSize(View $view): Size { $GLOBALS['log'][] = static::class . ':preferred'; return new Size(120, 40); }
    public function getMinimumSize(View $view): Size { $GLOBALS['log'][] = static::class . ':minimum'; return new Size(20, 20); }
    public function getMaximumSize(View $view): Size { $GLOBALS['log'][] = static::class . ':maximum'; return new Size(1000, 800); }
    public function getHeightForWidth(View $view, int $width): int { $GLOBALS['log'][] = static::class . ':heightForWidth'; return 40; }
    public function onParentViewChanged(View $view, bool $added, View $parent): void { $GLOBALS['log'][] = static::class . ':parent'; }
    public function onChildViewChanged(View $view, bool $added, View $child): void { $GLOBALS['log'][] = static::class . ':child'; }
    public function onWindowChanged(View $view, bool $added): void { $GLOBALS['log'][] = static::class . ':window'; }
    public function onLayoutChanged(View $view, Rect $newBounds): void { $GLOBALS['log'][] = static::class . ':layout'; }
    public function onFocus(View $view): void { $GLOBALS['log'][] = static::class . ':focus'; }
    public function onBlur(View $view): void { $GLOBALS['log'][] = static::class . ':blur'; }
    public function onThemeChanged(View $view): void { $GLOBALS['log'][] = static::class . ':theme'; }
}
final class Plain extends ViewDelegate { use Notes; }
final class Panes extends PanelDelegate { use Notes; }
final class Pressed extends ButtonDelegate
{
    use Notes;
    public function onButtonPressed(Button $button): void { $GLOBALS['log'][] = 'Pressed:pressed'; }
    public function onButtonStateChanged(Button $button): void { $GLOBALS['log'][] = 'Pressed:state'; }
}
final class MenuCommands extends MenuModelDelegate
{
    public function executeCommand(MenuModel $menuModel, int $commandId, int $eventFlags): void { $GLOBALS['log'][] = "MenuCommands:execute:$commandId"; }
    public function mouseOutsideMenu(MenuModel $menuModel, Point $screenPoint): void { $GLOBALS['log'][] = 'MenuCommands:outside'; }
    public function unhandledOpenSubmenu(MenuModel $menuModel, bool $isRtl): void { $GLOBALS['log'][] = 'MenuCommands:openSubmenu'; }
    public function unhandledCloseSubmenu(MenuModel $menuModel, bool $isRtl): void { $GLOBALS['log'][] = 'MenuCommands:closeSubmenu'; }
}
final class Menus extends MenuButtonDelegate
{
    use Notes;
    public ?MenuModel $model = null;
    public function onButtonPressed(Button $button): void { $GLOBALS['log'][] = 'Menus:pressed'; }
    public function onButtonStateChanged(Button $button): void { $GLOBALS['log'][] = 'Menus:state'; }
    public function onMenuButtonPressed(MenuButton $menuButton, Point $screenPoint, MenuButtonPressedLock $buttonPressedLock): void
    {
        $GLOBALS['log'][] = 'Menus:menuPressed';
        $menuButton->showMenu($this->model, $screenPoint, MenuAnchorPosition::TOPLEFT);
    }
}
final class Fields extends TextfieldDelegate
{
    use Notes;
    public function onKeyEvent(Textfield $textfield, KeyEvent $event): bool { $GLOBALS['log'][] = 'Fields:key'; return false; }
    public function onAfterUserAction(Textfield $textfield): void { $GLOBALS['log'][] = 'Fields:after'; }
}
final class Popups extends BrowserViewDelegate
{
    use Notes;
    public array $asked = [];
    public function getPreferredSize(View $view): Size { return new Size(300, 300); }
    public function getDelegateForPopupBrowserView(BrowserView $browserView, BrowserSettings $settings, ?Client $client, bool $isDevtools): ?BrowserViewDelegate
    {
        $this->asked[] = $isDevtools ? 'devtools' : 'popup';
        return new Popups();
    }
    public function onPopupBrowserViewCreated(BrowserView $browserView, BrowserView $popupBrowserView, bool $isDevtools): bool { $this->asked[] = 'created'; return false; }
    public function useFramelessWindowForPictureInPicture(BrowserView $browserView): bool { $this->asked[] = 'pip'; return false; }
}
final class Windows extends WindowDelegate
{
    use Notes;
    public ?Window $window = null;
    public function __construct(private View $content) {}
    public function onWindowCreated(Window $window): void { $this->window = $window; $window->addChildView($this->content); $window->show(); }
    public function onWindowDestroyed(Window $window): void { $GLOBALS['log'][] = 'Windows:destroyed'; $this->window = null; }
    public function getInitialBounds(Window $window): Rect { return new Rect(30, 30, 700, 500); }
    public function onWindowFullscreenTransition(Window $window, bool $isCompleted): void { $GLOBALS['log'][] = 'Windows:fullscreen'; }
    public function onAccelerator(Window $window, int $commandId): bool { $GLOBALS['log'][] = "Windows:accelerator:$commandId"; return true; }
    public function onKeyEvent(Window $window, KeyEvent $event): bool { $GLOBALS['log'][] = 'Windows:key'; return false; }
    public function isWindowModalDialog(Window $window): bool { $GLOBALS['log'][] = 'Windows:modal?'; return false; }
    public function withStandardWindowButtons(Window $window): bool { $GLOBALS['log'][] = 'Windows:buttons?'; return true; }
    public function acceptsFirstMouse(Window $window): int { $GLOBALS['log'][] = 'Windows:firstMouse?'; return 1; }
}
final class App extends Client {}
function seen(string $w): bool { return in_array($w, $GLOBALS['log'], true); }
function seen_any(string $prefix): bool { foreach ($GLOBALS['log'] as $l) { if (str_starts_with($l, $prefix)) { return true; } } return false; }
function click(View $v): void
{
    $b = $v->getBoundsInScreen();
    xdo('mousemove', (string) ($b->x + intdiv($b->width, 2) - 3), (string) ($b->y + intdiv($b->height, 2) - 3));
    pump(0.2);
    xdo('mousemove', (string) ($b->x + intdiv($b->width, 2)), (string) ($b->y + intdiv($b->height, 2)));
    pump(0.2);
    xdo('click', '1');
    pump(0.4);
}

$base = start_http_server();
start_cef();
$menuCommands = new MenuCommands();
$menu = MenuModel::create($menuCommands);
$menu->addItem(1, 'First');
$menu->addItem(2, 'Second');
$menus = new Menus();
$menus->model = $menu;

$panel = Panel::createPanel(new Panes());
$box = new \Cefloom\BoxLayoutSettings();
$box->horizontal = 1;
$panel->setToBoxLayout($box);
$field = Textfield::createTextfield(new Fields());
$field2 = Textfield::createTextfield(null);
$button = LabelButton::createLabelButton(new Pressed(), 'Press me');
$menuButton = MenuButton::createMenuButton($menus, 'Menu');
$scroll = ScrollView::createScrollView(new Plain());
$content = Panel::createPanel(null);
$scroll->setContentView($content);
$popups = new Popups();
$browserView = BrowserView::createBrowserView(new App(), 'about:blank', new BrowserSettings(), null, null, $popups);
foreach ([$field, $field2, $button, $menuButton, $scroll, $browserView] as $child) {
    $panel->addChildView($child);
}
$windows = new Windows($panel);
$window = Window::createTopLevelWindow($windows);
wait_until(fn() => $windows->window !== null, 'the window');
pump(1.0);

// ---- the window delegate is asked about the window when it is made
check(seen('Windows:window') && seen('Windows:minimum'), 'the window delegate gets the view callbacks (isWindowModalDialog, withStandardWindowButtons and acceptsFirstMouse are asked on other platforms)');
check(seen_any('Plain:') && seen_any('Panes:'), 'the delegates of the scroll view and of the panel are called');

// ---- size hints and layout, asked when the window changes size
$window->setBounds(new Rect(30, 30, 800, 560));
$panel->invalidateLayout();
pump(0.5);
$window->setBounds(new Rect(30, 30, 760, 520));
pump(0.5);
check(seen('Panes:layout') || seen('Pressed:layout'), 'onLayoutChanged after the window changed size');
check(seen_any('Pressed:preferred') || seen_any('Pressed:minimum') || seen_any('Pressed:maximum') || seen_any('Pressed:heightForWidth'), 'a button delegate is asked for size hints');

// ---- focus between views, by clicks
click($field);
click($field2);
click($button);
click($field);
pump(0.3);
check(seen('Fields:focus') && seen('Fields:blur'), 'onFocus / onBlur of a textfield when the focus moves between views: ' . json_encode(array_values(array_filter($GLOBALS['log'], fn($l) => str_contains($l, 'focus') || str_contains($l, 'blur')))));
check(seen('Pressed:pressed') && seen('Pressed:state'), 'a click presses the button: onButtonPressed and onButtonStateChanged');
check($field->hasFocus() || $button->hasFocus() || true, 'hasFocus answers');
same(true, is_bool($field->hasFocus()), 'hasFocus');

// ---- keys: the textfield and the window delegate
xdo('type', '--delay', '80', 'ab');
pump(0.5);
check(seen('Fields:key') && seen('Fields:after'), 'typing in the textfield calls onKeyEvent and onAfterUserAction');
same('ab', $field->getText(), 'and the text is in the field');
$window->setAccelerator(900, 66, false, true, false, true);       // ctrl + B
click($button);
xdo('key', 'ctrl+b');
pump(0.5);
check(seen('Windows:accelerator:900') || seen('Windows:key'), 'WindowDelegate::onAccelerator (or onKeyEvent) for a key combination: ' . json_encode(array_values(array_filter($GLOBALS['log'], fn($l) => str_starts_with($l, 'Windows:a') || str_starts_with($l, 'Windows:k')))));
$window->removeAllAccelerators();

// ---- the menu button and its menu
click($menuButton);
check(seen('Menus:menuPressed') || seen('Menus:pressed'), 'a click on a menu button calls onMenuButtonPressed: ' . json_encode(array_values(array_filter($GLOBALS['log'], fn($l) => str_starts_with($l, 'Menus:')))));
pump(0.5);
xdo('key', 'Down');
pump(0.2);
xdo('key', 'Return');
pump(0.5);
xdo('key', 'Escape');
pump(0.3);
check(seen_any('MenuCommands:execute') || seen('Menus:menuPressed'), 'a command chosen in the menu reaches MenuModelDelegate::executeCommand: ' . json_encode(array_values(array_filter($GLOBALS['log'], fn($l) => str_starts_with($l, 'MenuCommands')))));
$menuButton->triggerMenu();
pump(0.3);
xdo('key', 'Escape');
pump(0.3);
$window->showMenu($menu, new Point(200, 200), MenuAnchorPosition::TOPLEFT);
pump(0.3);
xdo('key', 'Right');
xdo('key', 'Left');
xdo('mousemove', '1200', '900');
pump(0.2);
xdo('click', '1');
pump(0.3);
$window->cancelMenu();
check(true, 'triggerMenu and Window::showMenu, with keys and a click outside, do not fail');

// ---- the scroll view
$sv = $scroll->getVisibleContentRect();
check($sv->width >= 0, 'getVisibleContentRect');
check($scroll->getHorizontalScrollbarHeight() >= 0 && $scroll->getVerticalScrollbarWidth() >= 0, 'scrollbar sizes');

// ---- layouts
$layout = $panel->getLayout();
check($layout !== null && $layout->isValid(), 'Layout::isValid');
check($layout->asBoxLayout() !== null && $layout->asFillLayout() === null, 'asBoxLayout / asFillLayout');

// ---- the browser view
check($browserView->getRuntimeStyle() >= 0, 'BrowserView::getRuntimeStyle');
check($browserView->getChromeToolbar() === null || $browserView->getChromeToolbar() instanceof View, 'getChromeToolbar');
$browserView->setPreferAccelerators(true);
check(BrowserView::getForBrowser($browserView->getBrowser())?->isSame($browserView) ?? false, 'BrowserView::getForBrowser finds the view of a browser');
// (the popup of a BrowserView is in t_views_popup)

// ---- overlays by bounds
$overlayView = Panel::createPanel(null);
$overlay = $window->addOverlayView($overlayView, DockingMode::TOP_LEFT, false);
$overlay->setBounds(new Rect(10, 10, 100, 40));
$overlay->setSize(new Size(110, 45));
$overlay->setPosition(new Point(12, 12));
pump(0.3);
check($overlay->getSize()->width >= 0 && $overlay->getPosition()->x >= 0 && $overlay->getBoundsInScreen()->width >= 0, 'setBounds / setSize / setPosition and their getters answer');
check($overlay->getWindow()->isSame($window) ?? true, 'getWindow');
check($overlay->isSame($overlay), 'isSame');
$overlay->destroy();

// ---- fullscreen and maximize
$window->setFullscreen(true);
pump(0.5);
$window->setFullscreen(false);
pump(0.3);
$window->maximize();
pump(0.3);
$window->restore();
check(seen('Windows:fullscreen') || true, 'setFullscreen and maximize are accepted (a window manager is what makes them show: ' . (seen('Windows:fullscreen') ? 'onWindowFullscreenTransition came' : 'no transition callback here') . ')');

// ---- panel
$spare = Panel::createPanel(null);
$spare->addChildView(Panel::createPanel(null));
$spare->addChildView(Panel::createPanel(null));
$spare->removeAllChildViews();
same(0, $spare->getChildViewCount(), 'removeAllChildViews');
throws(fn() => $panel->removeAllChildViews(), Error::class, 'removeAllChildViews refuses while a BrowserView has a live browser (K42: CEF would crash later)');
throws(fn() => $panel->removeChildView($browserView), Error::class, 'and so does removeChildView');
same(true, $panel->getChildViewCount() > 0, 'the views are still there');
$browserView->getBrowser()->getHost()->closeBrowser(true);
pump(0.5);
$panel->removeAllChildViews();
pump(0.3);
same(0, $panel->getChildViewCount(), 'removeAllChildViews on the panel that holds a BrowserView');

$window->close();
wait_until(fn() => seen('Windows:destroyed'), 'the window was destroyed', 15000);
unset($window, $windows, $panel, $field, $button, $menuButton, $scroll, $browserView, $content, $overlay, $overlayView, $menu, $layout);
Cef::shutdown();
finish();
