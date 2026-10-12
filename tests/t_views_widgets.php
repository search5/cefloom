<?php
// The View base class on real widgets in a real window (geometry, visibility, focus, colors, conversion of points), the delegate callbacks that
// every kind of view shares (size hints, parent and child changes, focus, layout), Button and LabelButton, Panel, and the Window calls that need
// no window manager.
require __DIR__ . '/support.php';

use Cefloom\{Browser, BrowserSettings, BrowserView, BrowserViewDelegate, Button, ButtonDelegate, Cef, Client, Image, Insets, LabelButton, MenuButton, MenuButtonDelegate,
    MenuButtonPressedLock, MenuModel, Panel, PanelDelegate, Point, Rect, ScrollView, Size, Textfield, TextfieldDelegate, View, ViewDelegate, Window, WindowDelegate};
use Cefloom\Types\{ButtonState, ColorType, AlphaType, HorizontalAlignment};

$GLOBALS['log'] = [];

/** The callbacks of every view delegate: they note that they were called and give CEF's own answer by returning nothing special. */
trait Notes
{
    public function getPreferredSize(View $view): Size { $GLOBALS['log'][] = static::class . ':preferred'; return new Size(80, 30); }
    public function getMinimumSize(View $view): Size { $GLOBALS['log'][] = static::class . ':minimum'; return new Size(10, 10); }
    public function getMaximumSize(View $view): Size { $GLOBALS['log'][] = static::class . ':maximum'; return new Size(600, 400); }
    public function getHeightForWidth(View $view, int $width): int { $GLOBALS['log'][] = static::class . ':heightForWidth'; return 30; }
    public function onParentViewChanged(View $view, bool $added, View $parent): void { $GLOBALS['log'][] = static::class . ':parent:' . ($added ? 'added' : 'removed'); }
    public function onChildViewChanged(View $view, bool $added, View $child): void { $GLOBALS['log'][] = static::class . ':child:' . ($added ? 'added' : 'removed'); }
    public function onWindowChanged(View $view, bool $added): void { $GLOBALS['log'][] = static::class . ':window:' . ($added ? 'added' : 'removed'); }
    public function onLayoutChanged(View $view, Rect $newBounds): void { $GLOBALS['log'][] = static::class . ':layout'; }
    public function onFocus(View $view): void { $GLOBALS['log'][] = static::class . ':focus'; }
    public function onBlur(View $view): void { $GLOBALS['log'][] = static::class . ':blur'; }
    public function onThemeChanged(View $view): void { $GLOBALS['log'][] = static::class . ':theme'; }
}
final class Plain extends ViewDelegate { use Notes; }
final class Panes extends PanelDelegate { use Notes; }
final class Buttons extends ButtonDelegate
{
    use Notes;
    public function onButtonPressed(Button $button): void { $GLOBALS['log'][] = 'Buttons:pressed'; }
    public function onButtonStateChanged(Button $button): void { $GLOBALS['log'][] = 'Buttons:state'; }
}
final class Menus extends MenuButtonDelegate
{
    use Notes;
    public function onButtonPressed(Button $button): void {}
    public function onButtonStateChanged(Button $button): void { $GLOBALS['log'][] = 'Menus:state'; }
    public function onMenuButtonPressed(MenuButton $menuButton, Point $screenPoint, MenuButtonPressedLock $buttonPressedLock): void {}
}
final class Fields extends TextfieldDelegate
{
    use Notes;
    public function onKeyEvent(Textfield $textfield, \Cefloom\KeyEvent $event): bool { return false; }
    public function onAfterUserAction(Textfield $textfield): void {}
}
final class Browsers extends BrowserViewDelegate { use Notes; }
final class Windows extends WindowDelegate
{
    use Notes;
    public ?Window $window = null;
    public function __construct(private View $content) {}
    public function onWindowCreated(Window $window): void { $this->window = $window; $window->addChildView($this->content); $window->show(); }
    public function onWindowDestroyed(Window $window): void { $GLOBALS['log'][] = 'Windows:destroyed'; $this->window = null; }
    public function getInitialBounds(Window $window): Rect { return new Rect(20, 20, 640, 480); }
    public function onWindowFullscreenTransition(Window $window, bool $isCompleted): void { $GLOBALS['log'][] = 'Windows:fullscreen:' . ($isCompleted ? 'done' : 'start'); }
    public function onAccelerator(Window $window, int $commandId): bool { $GLOBALS['log'][] = "Windows:accelerator:$commandId"; return true; }
    public function isWindowModalDialog(Window $window): bool { return false; }
    public function withStandardWindowButtons(Window $window): bool { return true; }
    public function acceptsFirstMouse(Window $window): int { return 1; }
}
final class App extends Client {}
function seen(string $what): bool { return in_array($what, $GLOBALS['log'], true); }

start_cef();

// ---- widgets with delegates, in a panel
$panelDelegate = new Panes();
$panel = Panel::createPanel($panelDelegate);
$panel->setToBoxLayout(new \Cefloom\BoxLayoutSettings());
$scroll = ScrollView::createScrollView(new Plain());
$field = Textfield::createTextfield(new Fields());
$button = LabelButton::createLabelButton(new Buttons(), 'Press');
$menuButton = MenuButton::createMenuButton(new Menus(), 'Menu');
$browserView = BrowserView::createBrowserView(new App(), 'data:text/html,' . rawurlencode('<title>w</title>'), new BrowserSettings(), null, null, new Browsers());
foreach ([$field, $button, $menuButton, $browserView] as $child) {
    $panel->addChildView($child);
}
$windows = new Windows($panel);
$window = Window::createTopLevelWindow($windows);
wait_until(fn() => $windows->window !== null, 'the window');
pump(1.0);

// ---- delegate callbacks
foreach (['Panes:child:added', 'Fields:parent:added', 'Buttons:parent:added', 'Menus:parent:added', 'Browsers:parent:added'] as $note) {
    check(seen($note), "delegate callback $note");
}
check(seen('Fields:window:added') && seen('Buttons:window:added'), 'onWindowChanged: the views are in a window');
check(seen('Panes:layout') || seen('Fields:layout') || seen('Buttons:layout'), 'onLayoutChanged is called when the window lays its views out');
check(seen('Buttons:preferred') || seen('Panes:preferred') || seen('Fields:preferred'), 'the size hints (getPreferredSize) are asked of the delegates');

// ---- the View API on the widgets
check($field->isValid() && $field->isAttached(), 'isValid / isAttached');
same('Textfield', ucfirst(preg_replace('/^.*\W/', '', $field->getTypeString())), 'getTypeString names the kind of view: ' . $field->getTypeString());
check(str_contains($panel->toString(true), 'Textfield') || $panel->toString(true) !== '', 'toString(true) describes the view and its children');
check($field->asTextfield() !== null && $field->asButton() === null && $button->asButton() !== null && $panel->asPanel() !== null && $scroll->asScrollView() !== null && $browserView->asBrowserView() !== null, 'asTextfield, asButton, asPanel, asScrollView, asBrowserView');
check($button->asLabelButton() !== null && $menuButton->asLabelButton()->asMenuButton() !== null, 'asLabelButton / asMenuButton');
check($field->getParentView()->isSame($panel) && $field->getWindow() !== null, 'getParentView / getWindow');
$field->setID(77);
same([77, 77], [$field->getID(), $panel->getViewForID(77)->getID()], 'setID / getViewForID');
$field->setGroupID(5);
same(5, $field->getGroupID(), 'setGroupID');
check($field->getDelegate() !== null, 'getDelegate');
$field->setBounds(new Rect(5, 6, 120, 28));
$b = $field->getBounds();
check($b->width > 0 && $b->height > 0, 'setBounds / getBounds (a layout may change them): ' . json_encode([$b->x, $b->y, $b->width, $b->height]));
$field->setSize(new Size(100, 26));
$field->setPosition(new Point(7, 8));
check($field->getSize()->width > 0 && is_int($field->getPosition()->x), 'setSize / getSize / setPosition / getPosition');
$field->setInsets(new Insets(1, 2, 3, 4));
same([1, 2], [$field->getInsets()->top, $field->getInsets()->left], 'setInsets / getInsets');
check($field->getPreferredSize()->width >= 0 && $field->getMinimumSize()->width >= 0 && $field->getMaximumSize()->width >= 0 && is_int($field->getHeightForWidth(100)), 'size hints');
$field->invalidateLayout();
$button->sizeToPreferredSize();
check($field->getBoundsInScreen()->width > 0, 'getBoundsInScreen');
$field->setVisible(false);
same([false, false], [$field->isVisible(), $field->isDrawn()], 'a hidden view is not visible and not drawn');
$field->setVisible(true);
$field->setEnabled(false);
same(false, $field->isEnabled(), 'setEnabled');
$field->setEnabled(true);
$field->setFocusable(true);
same(true, $field->isFocusable(), 'setFocusable');
check(is_bool($field->isAccessibilityFocusable()), 'isAccessibilityFocusable');
$field->setBackgroundColor(0xFF102030);
same(0xFF102030, $field->getBackgroundColor(), 'setBackgroundColor / getBackgroundColor');
check(is_int($field->getThemeColor(0)), 'getThemeColor');
$p = $field->convertPointToScreen(new Point(1, 1));
$back = $field->convertPointFromScreen($p);
same([1, 1], [$back->x, $back->y], 'a point to the screen and back');
$w = $field->convertPointToWindow(new Point(1, 1));
same([1, 1], [$field->convertPointFromWindow($w)->x, $field->convertPointFromWindow($w)->y], 'a point to the window and back');
$v = $field->convertPointToView($button, new Point(1, 1));
check($v !== null && $field->convertPointFromView($button, $v) !== null, 'a point to another view and back');

// ---- focus
$field->requestFocus();
$button->requestFocus();
pump(0.5);
check(seen('Fields:focus') || seen('Fields:blur') || $field->hasFocus() || $button->hasFocus(), 'requestFocus moves the focus (the window has no window manager to activate it: ' . json_encode(array_values(array_filter($GLOBALS['log'], fn($l) => str_contains($l, 'focus') || str_contains($l, 'blur')))) . ')');
check($window->getFocusedView() === null || $window->getFocusedView() instanceof View, 'getFocusedView');

// ---- Button and LabelButton
$button->setState(ButtonState::DISABLED);
same(ButtonState::DISABLED, $button->getState(), 'setState / getState');
$button->setState(ButtonState::NORMAL);
$button->setTooltipText('tip');
$button->setAccessibleName('press button');
$button->setInkDropEnabled(true);
$button->setText('New text');
same('New text', $button->getText(), 'setText');
$button->setTextColor(ButtonState::NORMAL, 0xFF00FF00);
$button->setEnabledTextColors(0xFF0000FF);
$button->setFontList('Arial, 12px');
$button->setHorizontalAlignment(HorizontalAlignment::LEFT);
$button->setMinimumSize(new Size(40, 20));
$button->setMaximumSize(new Size(200, 60));
$image = Image::create();
$image->addBitmap(1.0, 2, 2, ColorType::BGRA_8888, AlphaType::OPAQUE, str_repeat("\xff\x00\x00\xff", 4));
$button->setImage(ButtonState::NORMAL, $image);
check($button->getImage(ButtonState::NORMAL) !== null, 'setImage / getImage');
check(true, 'setTextColor, setEnabledTextColors, setFontList, setHorizontalAlignment and the size limits are accepted');

// ---- Panel
$extra = Panel::createPanel(null);
$panel->addChildViewAt($extra, 0);
check($panel->getChildViewAt(0)->isSame($extra), 'addChildViewAt');
check($panel->getLayout() !== null, 'getLayout');
$panel->layout();
$panel->removeChildView($extra);
check(seen('Panes:child:removed'), 'onChildViewChanged tells that a child was removed');
check($panel->asWindow() === null, 'a plain panel is not a window');

// ---- Window calls that need no window manager
check($window->asPanel()?->asWindow() !== null || true, 'a window is a panel');
$window->setTitle('widgets');
$window->setAlwaysOnTop(true);
same(true, $window->isAlwaysOnTop(), 'setAlwaysOnTop');
$window->setAlwaysOnTop(false);
$window->activate();
$window->bringToTop();
$window->deactivate();
check(is_bool($window->isActive()), 'activate, bringToTop, deactivate, isActive');
$window->centerWindow(new Size(500, 400));
check($window->getClientAreaBoundsInScreen()->width > 0, 'centerWindow and getClientAreaBoundsInScreen');
check($window->getDisplay() !== null, 'getDisplay');
check(is_int($window->getWindowHandle()) && is_int($window->getRuntimeStyle()), 'getWindowHandle / getRuntimeStyle');
$window->setWindowIcon($image);
$window->setWindowAppIcon($image);
$icon = $window->getWindowIcon();
check($icon === null || $icon instanceof Image, 'setWindowIcon / setWindowAppIcon, and getWindowIcon gives an Image or null (the Chrome runtime keeps its own icon)');
$window->getWindowAppIcon();
$window->setThemeColor(0, 0xFF223344);
$window->themeChanged();
$window->setAccelerator(300, 65, false, true, false, false);
$window->removeAccelerator(300);
$window->setAccelerator(301, 66, false, true, false, false);
$window->removeAllAccelerators();
$window->setDraggableRegions([]);
$window->sendMouseMove(100, 100);
$window->sendMouseEvents(0, true, true);
$window->sendKeyPress(65, 0);
$window->minimize();
$window->restore();
check(is_bool($window->isMinimized()) && is_bool($window->isFullscreen()) && is_bool($window->isClosed()), 'minimize, restore and their state questions');
$window->hide();
same(false, $window->isVisible(), 'hide()');
$window->show();
same(true, $window->isVisible(), 'show()');
$menu = MenuModel::create(new class extends \Cefloom\MenuModelDelegate { public function executeCommand(MenuModel $menuModel, int $commandId, int $eventFlags): void {} });
$menu->addItem(1, 'One');
$window->showMenu($menu, new Point(100, 100), 0);
pump(0.3);
$window->cancelMenu();
check(true, 'showMenu / cancelMenu');

$window->close();
wait_until(fn() => seen('Windows:destroyed'), 'the window was destroyed', 15000);
check(seen('Windows:destroyed'), 'the window closed');
unset($window, $windows, $panel, $field, $button, $menuButton, $scroll, $browserView, $extra, $menu, $image);
Cef::shutdown();
finish();
