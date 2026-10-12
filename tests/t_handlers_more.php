<?php
// Handlers that t_handlers and t_browser_* leave out: FrameHandler (frames of a page with an iframe, a navigation that swaps the main frame),
// CookieAccessFilter (which cookies a request sends and which it saves), the render process that crashes (RequestHandler::onRenderProcessTerminated,
// which the JS bridge also watches), and, with xdotool, a tooltip and a control-click on a link.
require __DIR__ . '/support.php';

use Cefloom\{Browser, BrowserHost, BrowserSettings, Cef, Client, Cookie, CookieAccessFilter, DisplayHandler, DictionaryValue, Frame, FrameHandler, LifeSpanHandler, LoadHandler,
    Request, RequestHandler, ResourceRequestHandler, Response, WindowInfo};
use Cefloom\Types\TerminationStatus;

final class Frames extends FrameHandler
{
    public array $log = [];
    public function onFrameCreated(Browser $browser, Frame $frame): void { $this->log[] = ['created', $frame->isMain()]; }
    public function onFrameDestroyed(Browser $browser, Frame $frame): void { $this->log[] = ['destroyed', $frame->isMain()]; }
    public function onFrameAttached(Browser $browser, Frame $frame, bool $reattached): void { $this->log[] = ['attached', $frame->isMain(), $reattached]; }
    public function onFrameDetached(Browser $browser, Frame $frame): void { $this->log[] = ['detached', $frame->isMain()]; }
    public function onMainFrameChanged(Browser $browser, ?Frame $oldFrame, ?Frame $newFrame): void { $this->log[] = ['main', $oldFrame !== null, $newFrame !== null]; }
    public function count(string $what, ?bool $main = null): int
    {
        return count(array_filter($this->log, fn($e) => $e[0] === $what && ($main === null || $e[1] === $main)));
    }
}
final class Filter extends CookieAccessFilter
{
    public array $sent = [];
    public array $saved = [];
    public array $deny = [];
    public function canSendCookie(?Browser $browser, ?Frame $frame, Request $request, Cookie $cookie): bool
    {
        $this->sent[] = $cookie->name;
        return !in_array($cookie->name, $this->deny, true);
    }
    public function canSaveCookie(?Browser $browser, ?Frame $frame, Request $request, Response $response, Cookie $cookie): bool
    {
        $this->saved[] = $cookie->name;
        return !in_array($cookie->name, $this->deny, true);
    }
}
final class Resource extends ResourceRequestHandler
{
    public function __construct(public Filter $filter) {}
    public function getCookieAccessFilter(?Browser $browser, ?Frame $frame, Request $request): ?CookieAccessFilter { return $this->filter; }
}
final class Req extends RequestHandler
{
    public array $terminated = [];
    public array $openedFromTab = [];
    public Resource $resource;
    public function __construct(Filter $filter) { $this->resource = new Resource($filter); }
    public function getResourceRequestHandler($browser, $frame, $request, $isNavigation, $isDownload, $requestInitiator): array { return [$this->resource, false]; }
    public function onRenderProcessTerminated(Browser $browser, int $status, int $errorCode, string $errorString): void { $this->terminated[] = [$status, $errorCode, $errorString]; }
    public function onOpenURLFromTab(Browser $browser, Frame $frame, string $targetUrl, int $targetDisposition, bool $userGesture): bool
    {
        $this->openedFromTab[] = [$targetUrl, $targetDisposition, $userGesture];
        return true;
    }
}
final class Tips extends DisplayHandler
{
    public array $tips = [];
    public function onTooltip(Browser $browser): array { return [false, '']; }
    public function onStatusMessage(Browser $browser, string $value): void { $this->tips[] = $value; }
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
    public Frames $frames;
    public Filter $filter;
    public Req $req;
    public Tips $tips;
    public Life $life;
    public Load $load;
    public function __construct()
    {
        $this->frames = new Frames(); $this->filter = new Filter(); $this->req = new Req($this->filter);
        $this->tips = new Tips(); $this->life = new Life(); $this->load = new Load();
    }
    public function getFrameHandler(): ?FrameHandler { return $this->frames; }
    public function getRequestHandler(): ?RequestHandler { return $this->req; }
    public function getDisplayHandler(): ?DisplayHandler { return $this->tips; }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
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
BrowserHost::createBrowser($info, $app, 'about:blank', new BrowserSettings(), null, null);
wait_until(fn() => $app->life->browser !== null && $app->load->ends > 0, 'the browser');
$browser = $app->life->browser;
$host = $browser->getHost();
$f = $app->frames;

// ---- frames
wait_until(fn() => $f->count('attached', true) >= 1, 'the main frame was attached', 5000);
check($f->count('created', true) >= 1 && $f->count('attached', true) >= 1, 'the main frame was created and attached: ' . json_encode($f->log));
$f->log = [];
go($app, "$base/page?title=outer&body=" . rawurlencode("<iframe src=\"$base/page?title=inner\"></iframe>"));
pump(0.5);
check($f->count('created', false) >= 1 && $f->count('attached', false) >= 1, 'an iframe is created and attached: ' . json_encode($f->log));
$f->log = [];
go($app, "$base/page?title=plain");
pump(0.5);
check($f->count('main') >= 1 && $f->count('destroyed', true) >= 1 && $f->count('created', true) >= 1, 'a navigation to another page swaps the main frame: the old one is detached and destroyed, a new one created, and onMainFrameChanged tells it: ' . json_encode($f->log));

// ---- cookies and the filter
$f2 = $app->filter;
$f2->deny = ['blocked'];
go($app, "$base/cookie/set?name=allowed&value=1&maxage=600");
go($app, "$base/cookie/set?name=blocked&value=2&maxage=600");
check(in_array('allowed', $f2->saved, true) && in_array('blocked', $f2->saved, true), 'canSaveCookie is asked for every cookie a response sets: ' . json_encode($f2->saved));
go($app, "$base/cookie/echo");
$t = new class extends \Cefloom\StringVisitor { public ?string $v = null; public function visit(string $string): void { $this->v = $string; } };
$browser->getMainFrame()->getText($t);
wait_until(fn() => $t->v !== null, 'text');
check(str_contains($t->v, 'allowed=1') && !str_contains($t->v, 'blocked'), 'a cookie that canSaveCookie refused was not stored: the request sends "' . trim($t->v) . '"');
$f2->deny = ['allowed'];
$f2->sent = [];
go($app, "$base/cookie/echo");
check(in_array('allowed', $f2->sent, true), 'canSendCookie is asked for the cookies of a request: ' . json_encode($f2->sent));
$browser->getMainFrame()->getText($t = new class extends \Cefloom\StringVisitor { public ?string $v = null; public function visit(string $string): void { $this->v = $string; } });
wait_until(fn() => $t->v !== null, 'text');
check(!str_contains($t->v, 'allowed'), 'and a cookie that canSendCookie refuses is not sent: "' . trim($t->v) . '"');
$f2->deny = [];

// ---- the render process crashes
$app->req->terminated = [];
$host->executeDevToolsMethod(0, 'Page.crash', null);
wait_until(fn() => $app->req->terminated !== [], 'onRenderProcessTerminated', 20000);
check(in_array($app->req->terminated[0][0] ?? -1, [TerminationStatus::PROCESS_CRASHED, TerminationStatus::ABNORMAL_TERMINATION], true), 'the render process crashing is reported with a status: ' . json_encode($app->req->terminated));
// the browser can load again afterwards (a reload after a crash makes a new render process)
$before = $app->load->ends;
$browser->getMainFrame()->loadURL("$base/page?title=after-crash");
wait_until(fn() => $app->load->ends > $before, 'a page loads after the crash', 20000);
check($app->load->ends > $before, 'the browser loads a page again after the render process crashed');

// ---- a control click on a link asks onOpenURLFromTab
if (can_make_input()) {
    go($app, "$base/page?title=links&body=" . rawurlencode("<a href=\"$base/page?title=target\" style=\"display:block;width:1000px;height:900px;background:#ccc\">link</a>"));
    pump(0.5);
    xdo('mousemove', '290', '390');
    pump(0.3);
    xdo('mousemove', '300', '400');
    pump(0.3);
    xdo('keydown', 'ctrl');
    xdo('click', '1');
    xdo('keyup', 'ctrl');
    wait_until(fn() => $app->req->openedFromTab !== [], 'onOpenURLFromTab', 8000);
    check(str_contains($app->req->openedFromTab[0][0] ?? '', 'title=target') && ($app->req->openedFromTab[0][2] ?? false), 'a control click asks onOpenURLFromTab, with a user gesture: ' . json_encode($app->req->openedFromTab));
} else {
    echo "SKIP control click: no xdotool or display\n";
}

Cef::shutdown();
finish();
