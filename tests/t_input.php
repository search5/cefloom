<?php
// Keyboard, focus and the context menu, driven with xdotool on a virtual display: KeyboardHandler (onPreKeyEvent, onKeyEvent),
// FocusHandler, ContextMenuHandler with ContextMenuParams and MenuModel (built and changed inside onBeforeContextMenu, a command chosen
// both from the real menu and through runContextMenu); and DragData as a plain object.
require __DIR__ . '/support.php';

use Cefloom\{Browser, BrowserHost, BrowserSettings, Cef, Client, ContextMenuHandler, ContextMenuParams, DragData, FocusHandler, Frame, KeyboardHandler,
    KeyEvent, LifeSpanHandler, LoadHandler, MenuModel, RunContextMenuCallback, WindowInfo};

if (!can_make_input()) {
    echo "SKIP: no xdotool or display\n";
    exit(0);
}

const FIRST_USER_ID = 26500;      // MENU_ID_USER_FIRST: command ids of our own items start here

final class Keys extends KeyboardHandler
{
    public array $pre = [];
    public array $keys = [];
    public bool $swallowX = false;
    public function onPreKeyEvent(Browser $browser, KeyEvent $event): array
    {
        $this->pre[] = [$event->type, $event->windowsKeyCode, $event->character];
        // [handled, isKeyboardShortcut]: only here can a key be kept from the page (onKeyEvent is called after the page had it)
        return [$this->swallowX && in_array($event->windowsKeyCode, [88, 120], true), false];
    }
    public function onKeyEvent(Browser $browser, KeyEvent $event): bool
    {
        $this->keys[] = [$event->type, $event->windowsKeyCode, $event->character, $event->modifiers, (bool) $event->focusOnEditableField];
        return false;
    }
}
final class Focus extends FocusHandler
{
    public array $log = [];
    public function onTakeFocus(Browser $browser, bool $next): void { $this->log[] = 'take:' . ($next ? 'next' : 'prev'); }
    public function onSetFocus(Browser $browser, int $source): bool { $this->log[] = 'set:' . $source; return false; }
    public function onGotFocus(Browser $browser): void { $this->log[] = 'got'; }
}
final class Menus extends ContextMenuHandler
{
    public array $params = [];
    public array $model = [];
    public array $commands = [];
    public int $dismissed = 0;
    public string $mode = 'native';        // native: CEF's menu, with our items; custom: runContextMenu answers
    public function onBeforeContextMenu(Browser $browser, Frame $frame, ContextMenuParams $params, MenuModel $model): void
    {
        $this->params = [
            'x' => $params->getXCoord(), 'y' => $params->getYCoord(), 'page' => $params->getPageUrl(), 'frame' => $params->getFrameUrl(),
            'editable' => $params->isEditable(), 'link' => $params->getLinkUrl(), 'selection' => $params->getSelectionText(),
            'flags' => $params->getTypeFlags(), 'media' => $params->getMediaType(), 'custom' => $params->isCustomMenu(), 'edit' => $params->getEditStateFlags(),
            'source' => $params->getSourceUrl(), 'image' => $params->hasImageContents(), 'title' => $params->getTitleText(), 'charset' => $params->getFrameCharset(),
        ];
        $this->model['count_before'] = $model->getCount();
        $model->clear();
        $model->addItem(FIRST_USER_ID, 'First');
        $model->addSeparator();
        $model->addCheckItem(FIRST_USER_ID + 1, 'Checked');
        $model->addRadioItem(FIRST_USER_ID + 2, 'Radio A', 7);
        $model->addRadioItem(FIRST_USER_ID + 3, 'Radio B', 7);
        $sub = $model->addSubMenu(FIRST_USER_ID + 4, 'Sub');
        $sub->addItem(FIRST_USER_ID + 5, 'Inside');
        $model->insertItemAt(0, FIRST_USER_ID + 6, 'At the top');
        $model->setChecked(FIRST_USER_ID + 1, true);
        $model->setEnabled(FIRST_USER_ID, false);
        $model->setLabel(FIRST_USER_ID + 6, 'Renamed');
        $model->setAccelerator(FIRST_USER_ID + 6, 65, false, true, false);
        $model->setVisible(FIRST_USER_ID + 3, false);
        $m = &$this->model;
        $m['count'] = $model->getCount();
        $m['labels'] = array_map(fn($i) => $model->getLabelAt($i), range(0, $model->getCount() - 1));
        $m['top'] = [$model->getCommandIdAt(0), $model->getLabel(FIRST_USER_ID + 6), $model->getIndexOf(FIRST_USER_ID)];
        $m['types'] = [$model->getType(FIRST_USER_ID), $model->getTypeAt(2)];
        $m['state'] = [$model->isEnabled(FIRST_USER_ID), $model->isChecked(FIRST_USER_ID + 1), $model->isVisible(FIRST_USER_ID + 3), $model->isVisible(FIRST_USER_ID + 2)];
        $m['group'] = [$model->getGroupId(FIRST_USER_ID + 2), $model->getGroupId(FIRST_USER_ID + 3)];
        $m['sub'] = [$model->getSubMenu(FIRST_USER_ID + 4)?->getCount(), $model->getSubMenu(FIRST_USER_ID + 4)?->isSubMenu(), $model->isSubMenu()];
        $m['accelerator'] = [$model->hasAccelerator(FIRST_USER_ID + 6), $model->getAccelerator(FIRST_USER_ID + 6)];
        $model->removeAccelerator(FIRST_USER_ID + 6);
        $m['accelerator_removed'] = $model->hasAccelerator(FIRST_USER_ID + 6);
        $model->remove(FIRST_USER_ID + 2);
        $m['after_remove'] = $model->getCount();
        $model->removeAt(0);                // the item at the top
        $m['after_removeAt'] = $model->getCount();
        $model->addItem(FIRST_USER_ID + 9, 'Pick me');
        $model->setLabelAt($model->getCount() - 1, 'Pick me');
        if ($this->mode === 'native') {
            $model->setEnabled(FIRST_USER_ID, true);
        }
    }
    public function runContextMenu(Browser $browser, Frame $frame, ContextMenuParams $params, MenuModel $model, RunContextMenuCallback $callback): bool
    {
        if ($this->mode !== 'custom') {
            return false;           // CEF shows its own menu
        }
        $callback->continue(FIRST_USER_ID + 9, 0);
        return true;
    }
    public function onContextMenuCommand(Browser $browser, Frame $frame, ContextMenuParams $params, int $commandId, int $eventFlags): bool
    {
        $this->commands[] = [$commandId, $eventFlags];
        return true;
    }
    public function onContextMenuDismissed(Browser $browser, Frame $frame): void { $this->dismissed++; }
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
    public Keys $keys;
    public Focus $focus;
    public Menus $menus;
    public Life $life;
    public Load $load;
    public function __construct() { $this->keys = new Keys(); $this->focus = new Focus(); $this->menus = new Menus(); $this->life = new Life(); $this->load = new Load(); }
    public function getKeyboardHandler(): ?KeyboardHandler { return $this->keys; }
    public function getFocusHandler(): ?FocusHandler { return $this->focus; }
    public function getContextMenuHandler(): ?ContextMenuHandler { return $this->menus; }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
}

$base = start_http_server();
start_cef();
$app = new App();
$info = new WindowInfo();
$info->bounds->width = 500;
$info->bounds->height = 400;
$body = '<input id="i" style="display:block;width:900px;height:100px;font-size:40px" oninput="document.title = this.value">'
    . '<a href="/hello" style="display:block;width:900px;height:100px">a link</a>';
BrowserHost::createBrowser($info, $app, "$base/page?title=input&body=" . rawurlencode($body), new BrowserSettings(), null, null);
wait_until(fn() => $app->life->browser !== null && $app->load->ends > 0, 'the page');
$browser = $app->life->browser;
pump(1.0);
// the window is at 10,10 with a toolbar of about 100 pixels: the input fills the top of the page
$inputX = '300';
$inputY = '150';
function click_input(string $x, string $y): void { xdo('mousemove', $x, (string) ((int) $y - 10)); pump(0.2); xdo('mousemove', $x, $y); pump(0.2); xdo('click', '1'); pump(0.5); }

// ---- focus: a click in the page gives it focus
click_input($inputX, $inputY);
check(in_array('got', $app->focus->log, true), 'onGotFocus after a click: ' . json_encode($app->focus->log));

// ---- keyboard: typing reaches the page and the handler
xdo('type', '--delay', '80', 'ab');
pump(0.8);
$typed = array_values(array_filter($app->keys->keys, fn($k) => in_array($k[1], [65, 66], true)));
check($typed !== [], 'onKeyEvent saw the keys A and B: ' . json_encode($app->keys->keys));
check(array_filter($app->keys->pre, fn($k) => $k[1] === 65) !== [], 'and onPreKeyEvent came first for them');
check(array_filter($app->keys->keys, fn($k) => $k[4]) !== [], 'focusOnEditableField is true in the input');
$app->keys->swallowX = true;
xdo('type', '--delay', '80', 'x');
pump(0.5);
$browser->getMainFrame()->executeJavaScript("document.title = 'value=' + document.getElementById('i').value", '', 0);
pump(0.3);
$visible = $browser->getHost()->getVisibleNavigationEntry();
check(str_contains($visible->getTitle(), 'value=ab') && !str_contains($visible->getTitle(), 'x'), 'a key that onPreKeyEvent handles does not reach the page: ' . $visible->getTitle());
$app->keys->swallowX = false;

// ---- focus leaves the page with Tab
$app->focus->log = [];
xdo('key', 'Tab');
pump(0.3);
xdo('key', 'Tab');
pump(0.3);
xdo('key', 'Tab');
pump(0.5);
check(in_array('take:next', $app->focus->log, true) || in_array('set:0', $app->focus->log, true) || $app->focus->log !== [], 'Tab past the last element gives a focus callback: ' . json_encode($app->focus->log));

// ---- the context menu, native: our items in CEF's menu
click_input($inputX, $inputY);
$app->menus->commands = [];
xdo('mousemove', $inputX, $inputY);
xdo('click', '3');
wait_until(fn() => $app->menus->params !== [], 'onBeforeContextMenu', 8000);
$p = $app->menus->params;
same([true, true], [$p['editable'], str_contains($p['page'], 'title=input')], 'ContextMenuParams: the input is editable, and the page URL');
same($p['page'], $p['frame'], 'the frame URL of the main frame is the page');
check($p['x'] > 0 && $p['y'] > 0, 'and the position of the click (' . $p['x'] . ',' . $p['y'] . ')');
check($app->menus->model['count_before'] > 0, 'the model starts with the items of CEF (' . $app->menus->model['count_before'] . ')');
$m = $app->menus->model;
same(7, $m['count'], 'the model has the items that were added (item, separator, check, two radios, submenu, and one inserted at the top)');
same('Renamed', $m['labels'][0], 'inserted at the top and renamed');
same([FIRST_USER_ID + 6, 'Renamed', 1], $m['top'], 'getCommandIdAt, getLabel, getIndexOf');
same([false, true, false, true], $m['state'], 'enabled / checked / visible state');
same([7, 7], $m['group'], 'radio items have their group');
same([1, true, false], $m['sub'], 'a submenu has its items and says it is one');
same(true, $m['accelerator'][0], 'an accelerator that was set');
same(false, $m['accelerator_removed'], 'and removed');
same([6, 5], [$m['after_remove'], $m['after_removeAt']], 'remove() and removeAt() take items away');
pump(0.5);
xdo('key', 'Down');
pump(0.2);
xdo('key', 'Return');
wait_until(fn() => $app->menus->commands !== [] || $app->menus->dismissed > 0, 'a command or a dismissal', 8000);
check($app->menus->commands !== [] || $app->menus->dismissed > 0, 'choosing in the menu gives onContextMenuCommand or onContextMenuDismissed: ' . json_encode([$app->menus->commands, $app->menus->dismissed]));
xdo('key', 'Escape');
pump(0.5);

// ---- the context menu, custom: runContextMenu answers
$app->menus->mode = 'custom';
$app->menus->commands = [];
xdo('mousemove', $inputX, $inputY);
xdo('click', '3');
wait_until(fn() => $app->menus->commands !== [], 'the command from runContextMenu', 8000);
same([[FIRST_USER_ID + 9, 0]], $app->menus->commands, 'runContextMenu: the command given to the callback arrives in onContextMenuCommand');

// ---- DragData as an object
$drag = DragData::create();
$drag->setLinkURL('http://example.com/x');
$drag->setLinkTitle('Example');
$drag->setFragmentText('some text');
$drag->setFragmentHtml('<b>some text</b>');
$drag->setFragmentBaseURL('http://example.com/');
same(['http://example.com/x', 'Example', 'some text', '<b>some text</b>', 'http://example.com/'],
    [$drag->getLinkURL(), $drag->getLinkTitle(), $drag->getFragmentText(), $drag->getFragmentHtml(), $drag->getFragmentBaseURL()], 'DragData keeps what is set');
same([true, false, false, false], [$drag->isLink(), $drag->isFragment(), $drag->isFile(), $drag->hasImage()], 'and says what it holds (a fragment is data that is neither a link nor a file)');
$text = DragData::create();
$text->setFragmentText('only text');
same([false, true], [$text->isLink(), $text->isFragment()], 'DragData with only text is a fragment');
$drag->addFile('/etc/hostname', 'host');
same([true, ['/etc/hostname']], [$drag->isFile(), $drag->getFilePaths()], 'a file added');
$drag->clearFilenames();
same(false, $drag->isFile(), 'clearFilenames');
$copy = $drag->clone();
$copy->setLinkTitle('Changed');
same(['Example', 'Changed'], [$drag->getLinkTitle(), $copy->getLinkTitle()], 'clone() is independent');

Cef::shutdown();
finish();
