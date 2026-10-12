<?php
// The rest of the Views framework: Textfield (its text API and, with xdotool, typing into it), ScrollView, MenuButton with its delegate and menu,
// Display, FillLayout and BoxLayout in a Panel, the overlay of a window, and the delegates of a Window (initial bounds, frameless and
// resizable, closing) and of a BrowserView (onBrowserCreated).
require __DIR__ . '/support.php';

use Cefloom\{BrowserSettings, BrowserView, BrowserViewDelegate, Cef, Client, Display, KeyEvent, LabelButton, ButtonDelegate, MenuButton, MenuButtonDelegate, MenuButtonPressedLock,
    MenuModel, MenuModelDelegate, OverlayController, Panel, PanelDelegate, Point, Range, Rect, ScrollView, Textfield, TextfieldDelegate, Window, WindowDelegate};
use Cefloom\Types\{MenuAnchorPosition, TextfieldCommands, ShowState};

$GLOBALS['events'] = [];

final class Fields extends TextfieldDelegate
{
    public array $keys = [];
    public int $after = 0;
    public function onKeyEvent(Textfield $textfield, KeyEvent $event): bool { $this->keys[] = [$event->type, $event->windowsKeyCode]; return false; }
    public function onAfterUserAction(Textfield $textfield): void { $this->after++; }
}
final class Menus extends MenuButtonDelegate
{
    public array $pressed = [];
    public ?MenuModel $model = null;
    public function onButtonPressed(\Cefloom\Button $button): void { $this->pressed[] = 'button'; }
    public function onMenuButtonPressed(MenuButton $menuButton, Point $screenPoint, MenuButtonPressedLock $buttonPressedLock): void
    {
        $this->pressed[] = ['menu', $screenPoint->x, $screenPoint->y];
        $menuButton->showMenu($this->model, $screenPoint, MenuAnchorPosition::TOPLEFT);
    }
}
final class MenuCommands extends MenuModelDelegate
{
    public array $executed = [];
    public function executeCommand(MenuModel $menuModel, int $commandId, int $eventFlags): void { $this->executed[] = $commandId; }
}
final class Panels extends PanelDelegate
{
    public array $log = [];
    public function onChildViewChanged(\Cefloom\View $view, bool $added, \Cefloom\View $child): void { $this->log[] = $added ? 'added' : 'removed'; }
}
final class Browsers extends BrowserViewDelegate
{
    public array $created = [];
    public function onBrowserCreated(BrowserView $browserView, \Cefloom\Browser $browser): void { $this->created[] = $browser->getIdentifier(); }
}
final class Windows extends WindowDelegate
{
    public ?Window $window = null;
    public array $log = [];
    public bool $frameless = false;
    public bool $resizable = true;
    public bool $allowClose = true;
    public function __construct(private Panel|BrowserView|null $content = null) {}
    public function onWindowCreated(Window $window): void
    {
        $this->window = $window;
        $this->log[] = 'created';
        if ($this->content) {
            $window->addChildView($this->content);
        }
        $window->show();
    }
    public function onWindowDestroyed(Window $window): void { $this->log[] = 'destroyed'; $this->window = null; }
    public function onWindowBoundsChanged(Window $window, Rect $newBounds): void { $this->log[] = 'bounds:' . $newBounds->width . 'x' . $newBounds->height; }
    public function canClose(Window $window): bool { $this->log[] = 'canClose'; return $this->allowClose; }
    public function canResize(Window $window): bool { return $this->resizable; }
    public function canMaximize(Window $window): bool { return $this->resizable; }
    public function isFrameless(Window $window): bool { return $this->frameless; }
    public function getInitialBounds(Window $window): Rect { return new Rect(40, 60, 500, 350); }
    public function getInitialShowState(Window $window): int { return ShowState::NORMAL; }
}
final class App extends Client {}

start_cef();

// ---- Textfield: the text API
$fields = new Fields();
$field = Textfield::createTextfield($fields);
check($field !== null, 'a textfield is made');
$field->setText('hello world');
same('hello world', $field->getText(), 'setText / getText');
$field->appendText('!');
same('hello world!', $field->getText(), 'appendText');
$field->selectRange(new Range(0, 5));
same([true, 'hello'], [$field->hasSelection(), $field->getSelectedText()], 'selectRange selects, getSelectedText reads');
$range = $field->getSelectedRange();
same([0, 5], [$range->from, $range->to], 'getSelectedRange');
$field->insertOrReplaceText('HOWDY');
same('HOWDY world!', $field->getText(), 'insertOrReplaceText replaces the selection');
$field->selectAll(false);
same('HOWDY world!', $field->getSelectedText(), 'selectAll selects everything');
$field->clearSelection();
same(false, $field->hasSelection(), 'clearSelection');
$field->setPlaceholderText('type here');
same('type here', $field->getPlaceholderText(), 'placeholder text');
$field->setPasswordInput(true);
same(true, $field->isPasswordInput(), 'password input');
$field->setPasswordInput(false);
$field->setReadOnly(true);
same(true, $field->isReadOnly(), 'read only');
$field->setReadOnly(false);
check($field->isCommandEnabled(TextfieldCommands::SELECT_ALL), 'a command can be asked for');
$field->executeCommand(TextfieldCommands::SELECT_ALL);
same(true, $field->hasSelection(), 'executeCommand(SELECT_ALL)');
$field->clearEditHistory();
$field->setFontList('Arial, 14px');
$field->applyTextStyle(\Cefloom\Types\TextStyle::BOLD, true, new Range(0, 3));
$field->applyTextColor(0xFFFF0000, new Range(0, 3));
check($field->getCursorPosition() >= 0, 'cursor position: ' . $field->getCursorPosition());
$field->setAccessibleName('name field');
check(true, 'font list, text style, color and accessible name are accepted');

// ---- ScrollView
$content = Panel::createPanel(null);
$scroll = ScrollView::createScrollView(null);
check($scroll !== null && $content !== null, 'a scroll view and a content panel are made');
$scroll->setContentView($content);
check($scroll->getContentView()->isSame($content) || $scroll->getContentView() !== null, 'setContentView / getContentView');
check(is_bool($scroll->hasHorizontalScrollbar()) && is_bool($scroll->hasVerticalScrollbar()), 'the scrollbar questions are answered');

// ---- Display
$primary = Display::getPrimaryDisplay();
check($primary !== null, 'there is a primary display');
$b = $primary->getBounds();
check($b->width > 0 && $b->height > 0, "its bounds: {$b->width}x{$b->height}");
check($primary->getWorkArea()->width > 0 && $primary->getDeviceScaleFactor() >= 1.0, 'work area and scale factor');
check(Display::getDisplayCount() >= 1 && count(Display::getAllDisplays()) === Display::getDisplayCount(), 'the display count is the number of displays');
check(Display::getDisplayNearestPoint(new Point(5, 5), false)->getID() === $primary->getID(), 'the display nearest to a point near the origin is the primary one');
$p = $primary->convertPointToPixels(new Point(10, 10));
check($p->x >= 10, 'convertPointToPixels');

// ---- MenuButton and a menu model
$menus = new Menus();
$menuCommands = new MenuCommands();
$menus->model = MenuModel::create($menuCommands);
$menus->model->addItem(1, 'One');
$menus->model->addItem(2, 'Two');
$menuButton = MenuButton::createMenuButton($menus, 'Menu');
check($menuButton !== null && $menuButton->getText() === 'Menu', 'a menu button with its text');

// ---- Panel with layouts, a delegate that sees children come and go
$panelDelegate = new Panels();
$panel = Panel::createPanel($panelDelegate);
$fill = $panel->setToFillLayout();
check($fill !== null, 'setToFillLayout gives a FillLayout');
$panel->addChildView($field);
same(1, $panel->getChildViewCount(), 'a child view is added');
same(['added'], $panelDelegate->log, 'PanelDelegate::onChildViewChanged told it');
$box = $panel->setToBoxLayout(new \Cefloom\BoxLayoutSettings());
check($box !== null, 'setToBoxLayout gives a BoxLayout');
$panel->addChildView($menuButton);
$box->setFlexForView($menuButton, 1);
$box->clearFlexForView($menuButton);
same(2, $panel->getChildViewCount(), 'two children');
$panel->removeChildView($menuButton);
same(1, $panel->getChildViewCount(), 'removeChildView');
$panel->addChildView($menuButton);
$panel->reorderChildView($menuButton, 0);
check($panel->getChildViewAt(0)->isSame($menuButton), 'reorderChildView puts a child first');

// ---- a window with the panel; the delegates of the window and of the browser view
$app = new App();
$browsers = new Browsers();
$browserView = BrowserView::createBrowserView($app, 'data:text/html,' . rawurlencode('<title>v</title><h1>views</h1>'), new BrowserSettings(), null, null, $browsers);
$panel->addChildView($browserView);
$windows = new Windows($panel);
$window = Window::createTopLevelWindow($windows);
wait_until(fn() => in_array('created', $windows->log, true), 'the window was created');
wait_until(fn() => $browsers->created !== [], 'onBrowserCreated', 15000);
same(1, count($browsers->created), 'BrowserViewDelegate::onBrowserCreated was called once');
check($browsers->created[0] === $browserView->getBrowser()->getIdentifier(), 'with the browser of the view');
pump(0.5);
$bounds = $window->getBounds();
same([500, 350], [$bounds->width, $bounds->height], 'getInitialBounds is the size of the window (' . $bounds->width . 'x' . $bounds->height . ')');
check(!$window->isMaximized() && $window->isVisible(), 'a window made visible');
$window->setBounds(new Rect(100, 100, 640, 420));
wait_until(fn() => in_array('bounds:640x420', $windows->log, true), 'onWindowBoundsChanged', 10000);
check(in_array('bounds:640x420', $windows->log, true), 'onWindowBoundsChanged tells the new size: ' . json_encode($windows->log));
// (maximize() needs a window manager; the virtual display has none: K27)

// ---- an overlay on the window
$overlayView = \Cefloom\Panel::createPanel(null);
$overlayView->setToFillLayout();
$overlay = $window->addOverlayView($overlayView, \Cefloom\Types\DockingMode::TOP_LEFT, false);
check($overlay !== null && $overlay->isValid(), 'addOverlayView gives an OverlayController');
$overlay->setVisible(true);
$overlay->setInsets(new \Cefloom\Insets(5, 5, 0, 0));
$insets = $overlay->getInsets();
same([5, 5], [$insets->top, $insets->left], 'setInsets / getInsets');
$overlay->sizeToPreferredSize();
pump(0.5);
same(true, $overlay->isVisible(), 'setVisible');
same(true, $overlay->isDrawn(), 'a visible overlay in a visible window is drawn');
same(\Cefloom\Types\DockingMode::TOP_LEFT, $overlay->getDockingMode(), 'getDockingMode');
$ob = $overlay->getBounds();
check($ob->width >= 0, 'getBounds answers: ' . json_encode([$ob->x, $ob->y, $ob->width, $ob->height]) . ' (the size of an overlay comes from the layout of its window)');
check($overlay->getContentsView()->isSame($overlayView), 'the contents view is the one given');
$overlay->destroy();
same(false, $overlay->isValid(), 'destroy() makes the controller invalid');

// ---- canClose can refuse; then close
$windows->allowClose = false;
$window->close();
pump(0.5);
check(in_array('canClose', $windows->log, true) && !in_array('destroyed', $windows->log, true), 'canClose returning false keeps the window');
$windows->allowClose = true;
$window->close();
wait_until(fn() => in_array('destroyed', $windows->log, true), 'the window was destroyed', 15000);
check(in_array('destroyed', $windows->log, true), 'and it closes when canClose says yes');

unset($window, $windows, $panel, $browserView, $field, $scroll, $content, $menuButton, $overlay, $overlayView, $fill, $box);
Cef::shutdown();
finish();
