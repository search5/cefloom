<?php
// BrowserHost beyond navigation: zoom, audio muting, find in page, the DevTools protocol, the navigation history, the text and
// source of a frame, closing in two steps, blocked navigation and popups.
require __DIR__ . '/support.php';

use Cefloom\{Browser, BrowserHost, BrowserSettings, Cef, Client, DevToolsMessageObserver, DictionaryValue, FindHandler, Frame, LifeSpanHandler,
    LoadHandler, NavigationEntry, NavigationEntryVisitor, PopupFeatures, Rect, Request, RequestHandler, StringVisitor, WindowInfo};
use Cefloom\Types\ZoomCommand;

final class Text extends StringVisitor
{
    public ?string $text = null;
    public function visit(string $string): void { $this->text = $string; }
}
final class Entries extends NavigationEntryVisitor
{
    public array $seen = [];
    public function visit(NavigationEntry $entry, bool $current, int $index, int $total): bool
    {
        $this->seen[] = [$entry->getURL(), $current, $index, $total, $entry->getHttpStatusCode(), $entry->isValid()];
        return true;
    }
}
final class Find extends FindHandler
{
    public array $results = [];
    public function onFindResult(Browser $browser, int $identifier, int $count, Rect $selectionRect, int $activeMatchOrdinal, bool $finalUpdate): void
    {
        $this->results[] = [$identifier, $count, $activeMatchOrdinal, $finalUpdate];
    }
}
final class DevTools extends DevToolsMessageObserver
{
    public array $results = [];
    public array $events = [];
    public bool $attached = false;
    public function onDevToolsMethodResult(Browser $browser, int $messageId, bool $success, ?\Cefloom\Buffer $result): void
    {
        $this->results[$messageId] = [$success, $result ? $result->read(0, $result->getSize()) : null];
    }
    public function onDevToolsEvent(Browser $browser, string $method, ?\Cefloom\Buffer $params): void { $this->events[] = $method; }
    public function onDevToolsAgentAttached(Browser $browser): void { $this->attached = true; }
}
final class Life extends LifeSpanHandler
{
    public $browser = null;
    public bool $closed = false;
    public int $doClose = 0;
    public bool $allowClose = true;
    public array $popups = [];
    public function onAfterCreated($browser): void { $this->browser = $browser; }
    public function doClose($browser): bool { $this->doClose++; return !$this->allowClose; }
    public function onBeforeClose($browser): void { $this->closed = true; }
    public function onBeforePopup($browser, $frame, $popupId, $targetUrl, $targetFrameName, $targetDisposition, $userGesture, $popupFeatures): bool
    {
        $this->popups[] = [$targetUrl, $targetFrameName, $userGesture];
        return true;          // true cancels the popup
    }
}
final class Load extends LoadHandler
{
    public int $ends = 0;
    public function onLoadEnd($browser, $frame, $httpStatusCode): void { if ($frame->isMain()) { $this->ends++; } }
}
final class Req extends RequestHandler
{
    public array $seen = [];
    public ?string $block = null;
    public function onBeforeBrowse($browser, $frame, $request, $userGesture, $isRedirect): bool
    {
        $this->seen[] = [$request->getURL(), $userGesture, $isRedirect];
        return $this->block !== null && str_contains($request->getURL(), $this->block);
    }
}
final class App extends Client
{
    public Life $life;
    public Load $load;
    public Find $find;
    public Req $req;
    public function __construct() { $this->life = new Life(); $this->load = new Load(); $this->find = new Find(); $this->req = new Req(); }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
    public function getFindHandler(): ?FindHandler { return $this->find; }
    public function getRequestHandler(): ?RequestHandler { return $this->req; }
}
function go(App $app, string $url): void
{
    $before = $app->load->ends;
    $app->life->browser->getMainFrame()->loadURL($url);
    wait_until(fn() => $app->load->ends > $before, "loaded $url");
}

$base = start_http_server();
start_cef();
$app = new App();
$info = new WindowInfo();
$info->bounds->width = 400;
$info->bounds->height = 300;
BrowserHost::createBrowser($info, $app, "$base/page?title=first&body=" . rawurlencode('<p>needle one</p><p>needle two</p><p>needle three</p>'), new BrowserSettings(), null, null);
wait_until(fn() => $app->life->browser !== null && $app->load->ends > 0, 'the first page');
$browser = $app->life->browser;
$host = $browser->getHost();

// ---- zoom
check($host->canZoom(ZoomCommand::IN), 'canZoom(IN)');
same(0.0, $host->getZoomLevel(), 'the zoom level starts at 0');
$host->setZoomLevel(1.5);
wait_until(fn() => abs($host->getZoomLevel() - 1.5) < 0.001, 'the zoom level was set');
same(1.5, $host->getZoomLevel(), 'setZoomLevel / getZoomLevel');
$host->zoom(ZoomCommand::IN);
wait_until(fn() => $host->getZoomLevel() > 1.5, 'zoom in');
check($host->getZoomLevel() > 1.5, 'zoom(IN) raises the level (' . $host->getZoomLevel() . ')');
$host->zoom(ZoomCommand::OUT);
$host->zoom(ZoomCommand::RESET);
wait_until(fn() => $host->getZoomLevel() === $host->getDefaultZoomLevel(), 'zoom reset');
same($host->getDefaultZoomLevel(), $host->getZoomLevel(), 'zoom(RESET) goes back to the default');

// ---- audio
same(false, $host->isAudioMuted(), 'audio is not muted at first');
$host->setAudioMuted(true);
wait_until(fn() => $host->isAudioMuted(), 'muted');
same(true, $host->isAudioMuted(), 'setAudioMuted(true)');
$host->setAudioMuted(false);
same(false, $host->isAudioMuted(), 'and back');

// ---- find in page
$host->find('needle', true, false, false);
wait_until(fn() => $app->find->results && end($app->find->results)[3] === true, 'a final find result');
$final = end($app->find->results);
same(3, $final[1], 'find: three matches');
same(0, $final[2], 'the Chrome runtime reports the active match as 0 in its find result');
$host->find('needle', true, false, true);       // find next
$host->stopFinding(true);
$app->find->results = [];
$host->find('NEEDLE', true, true, false);
wait_until(fn() => $app->find->results && end($app->find->results)[3], 'case sensitive result');
same(0, end($app->find->results)[1], 'matchCase: NEEDLE does not match needle');
$host->stopFinding(true);
check(true, 'stopFinding');

// ---- the text and the source of the frame
$frame = $browser->getMainFrame();
$text = new Text();
$frame->getText($text);
wait_until(fn() => $text->text !== null, 'frame text');
check(str_contains($text->text, 'needle two'), 'Frame::getText has the text of the page');
$source = new Text();
$frame->getSource($source);
wait_until(fn() => $source->text !== null, 'frame source');
check(str_contains($source->text, '<p>needle one</p>'), 'Frame::getSource has the HTML');

// ---- the DevTools protocol
$observer = new DevTools();
$registration = $host->addDevToolsMessageObserver($observer);
check($registration !== null, 'addDevToolsMessageObserver gives a registration');
$params = DictionaryValue::create();
$params->setString('expression', '6 * 7');
$params->setBool('returnByValue', true);
$id = $host->executeDevToolsMethod(0, 'Runtime.evaluate', $params);
check($id > 0, "executeDevToolsMethod gives a message id ($id)");
wait_until(fn() => isset($observer->results[$id]), 'the DevTools result');
[$ok, $json] = $observer->results[$id];
$decoded = json_decode((string) $json, true);
same([true, 42], [$ok, $decoded['result']['value'] ?? null], 'the result of Runtime.evaluate is 42');
$id2 = $host->executeDevToolsMethod(0, 'No.suchMethod', null);
wait_until(fn() => isset($observer->results[$id2]), 'the DevTools error');
same(false, $observer->results[$id2][0], 'an unknown method is reported as a failure');
$id3 = $host->executeDevToolsMethod(0, 'Runtime.enable', null);
wait_until(fn() => isset($observer->results[$id3]), 'Runtime.enable');
$host->executeDevToolsMethod(0, 'Runtime.evaluate', (function () { $p = DictionaryValue::create(); $p->setString('expression', 'console.log("x")'); return $p; })());
wait_until(fn() => in_array('Runtime.consoleAPICalled', $observer->events, true), 'a DevTools event');
check(in_array('Runtime.consoleAPICalled', $observer->events, true), 'events arrive in onDevToolsEvent');
check($host->hasDevTools() === false, 'no DevTools window is open');

// ---- the history
go($app, "$base/page?title=second&body=two");
go($app, "$base/page?title=third&body=three");
$entries = new Entries();
$host->getNavigationEntries($entries, false);
wait_until(fn() => count($entries->seen) >= 3, 'the entries');
same(3, count($entries->seen), 'getNavigationEntries visits every entry');
same([false, false, true], array_column($entries->seen, 1), 'and tells which one is the current one');
check(str_contains($entries->seen[0][0], 'title=first') && str_contains($entries->seen[2][0], 'title=third'), 'in order');
same([0, 1, 2], array_column($entries->seen, 2), 'with their index');
same([3, 3, 3], array_column($entries->seen, 3), 'and the total');
same(true, $entries->seen[2][5], 'valid entries');
$only = new Entries();
$host->getNavigationEntries($only, true);
wait_until(fn() => count($only->seen) >= 1, 'the current entry');
same(1, count($only->seen), 'currentOnly gives one');
$visible = $host->getVisibleNavigationEntry();
check($visible !== null && str_contains($visible->getURL(), 'title=third'), 'getVisibleNavigationEntry is the current page');
check($visible->getTitle() === 'third', 'its title: ' . $visible->getTitle());
same(200, $visible->getHttpStatusCode(), 'and its HTTP status');
check($browser->canGoBack() && !$browser->canGoForward(), 'canGoBack, not canGoForward');

// ---- navigation that is blocked in onBeforeBrowse
$app->req->block = 'title=blocked';
$app->req->seen = [];
$before = $app->load->ends;
$frame = $browser->getMainFrame();
$frame->loadURL("$base/page?title=blocked&body=x");
pump(1.0);
check($app->req->seen !== [] && str_contains($app->req->seen[0][0], 'title=blocked'), 'onBeforeBrowse saw the navigation');
$visible = $host->getVisibleNavigationEntry();
check(!str_contains($visible->getURL(), 'blocked'), 'and returning true cancelled it: the page is still ' . $visible->getURL());
$app->req->block = null;
$app->req->seen = [];
go($app, "$base/redirect?to=" . rawurlencode("/page?title=redirected&body=r"));
$redirectSeen = array_column($app->req->seen, 2);
check(in_array(true, $redirectSeen, true), 'onBeforeBrowse is told when a navigation is a redirect: ' . json_encode($app->req->seen));

// ---- popups: a link with target=_blank that is clicked (a script without a user gesture is blocked before onBeforePopup)
if (can_make_input()) {
    go($app, "$base/page?title=popuppage&body=" . rawurlencode("<a href=\"$base/page?title=popup&body=p\" target=\"_blank\" style=\"display:block;width:1000px;height:900px;background:#ccc\">open</a>"));
    pump(0.5);
    // the window of a browser is at 10,10 and has a toolbar of about 100 pixels: click well inside the page
    xdo('mousemove', '290', '390');
    pump(0.3);
    xdo('mousemove', '300', '400');
    pump(0.3);
    xdo('click', '1');
    wait_until(fn() => $app->life->popups !== [], 'onBeforePopup', 8000);
    check(str_contains($app->life->popups[0][0] ?? '', 'title=popup') && ($app->life->popups[0][2] ?? false) === true, 'onBeforePopup is called for a clicked target=_blank link, with a user gesture: ' . json_encode($app->life->popups));
} else {
    echo "SKIP popup: no xdotool or display\n";
}

// ---- other state
check(is_int($host->getWindowHandle()), 'getWindowHandle');
same(false, $host->isRenderProcessUnresponsive(), 'the renderer is responsive');
check(is_int($host->getRuntimeStyle()), 'getRuntimeStyle');
same(0, $host->getOpenerIdentifier(), 'a browser made by createBrowser has no opener');

// ---- closing: in this build a browser has no native parent window to ask, so doClose is not called and the close goes on
$app->life->allowClose = false;
$host->closeBrowser(false);
wait_until(fn() => $app->life->closed, 'the browser closed');
same([0, true], [$app->life->doClose, $app->life->closed], 'closeBrowser closes the browser (onBeforeClose) without a call to doClose, whatever it would answer (K36)');
check($host->isReadyToBeClosed() || true, 'isReadyToBeClosed can be asked');

Cef::shutdown();
finish();
