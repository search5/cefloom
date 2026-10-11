<?php
// Browser + Frame: navigation, history, identifiers, frames, getSource/getText, loadRequest, executeJavaScript.
require __DIR__ . '/t_browser_common.php';

use Cefloom\{Browser, BrowserHost, Frame, Request, StringVisitor};

final class Collect extends StringVisitor
{
    public ?string $text = null;
    public function visit(string $string): void { $this->text = $string; }
}

page('/index.html', html('index', '<p id=a>first page</p>'));
page('/two.html', html('two', '<p>second page</p><iframe name="kid" src="/kid.html"></iframe><iframe src="/kid2.html"></iframe>'));
page('/kid.html', html('kid', 'kid frame text'));
page('/kid2.html', html('kid2', 'kid2'));
page('/post', html('post', 'posted'), 'text/html');

start_cef();
$c = new TestClient();
$b = open_browser($c);
wait_until(fn() => $c->load->mainEnds >= 1, 'first load');

check($b instanceof Browser, 'Browser instance');
check($b->isValid(), 'isValid');
check($b->getIdentifier() > 0, 'identifier positive');
same(false, $b->isPopup(), 'not a popup');
same(true, $b->hasDocument(), 'hasDocument');
same(false, $b->canGoBack(), 'cannot go back at start');
same(false, $b->canGoForward(), 'cannot go forward at start');
check($b->isSame($b), 'isSame self');
check($b->getHost() instanceof BrowserHost, 'getHost');
same($b->getIdentifier(), $b->getHost()->getBrowser()->getIdentifier(), 'host->getBrowser same id');
check(BrowserHost::getBrowserByIdentifier($b->getIdentifier())?->isSame($b) === true, 'getBrowserByIdentifier');
same(null, BrowserHost::getBrowserByIdentifier(99999), 'getBrowserByIdentifier unknown -> null');

$f = $b->getMainFrame();
check($f instanceof Frame, 'main frame');
same(true, $f->isMain(), 'isMain');
same(true, $f->isValid(), 'frame isValid');
same(BASE . '/index.html', $f->getURL(), 'frame url');
same(null, $f->getParent(), 'main frame has no parent');
same('', $f->getName(), 'main frame name empty');
check($f->getIdentifier() !== '', 'frame identifier non-empty string');
check($f->getBrowser()->isSame($b), 'frame->getBrowser');
same(1, $b->getFrameCount(), 'frame count 1');
same([$f->getIdentifier()], $b->getFrameIdentifiers(), 'frame identifiers');
check($b->getFrameByIdentifier($f->getIdentifier())?->isMain() === true, 'getFrameByIdentifier main');
same(null, $b->getFrameByIdentifier('nope'), 'getFrameByIdentifier unknown');
same(null, $b->getFrameByName('nope'), 'getFrameByName unknown');

// getSource / getText with StringVisitor (async)
$v = new Collect();
$f->getSource($v);
wait_until(fn() => $v->text !== null, 'getSource visitor');
check(str_contains((string)$v->text, 'first page') && str_contains((string)$v->text, '<p id="a">'), 'source has markup');
$t = new Collect();
$f->getText($t);
wait_until(fn() => $t->text !== null, 'getText visitor');
same('first page', trim((string)$t->text), 'text content');

// second navigation, with iframes
navigate($c, BASE . '/two.html');
wait_until(fn() => $b->getFrameCount() === 3, 'three frames');
$f = $b->getMainFrame();
same(BASE . '/two.html', $f->getURL(), 'url after loadURL');
same(true, $b->canGoBack(), 'canGoBack after navigation');
same(false, $b->canGoForward(), 'canGoForward still false');
$ids = $b->getFrameIdentifiers();
same(3, count($ids), 'frame identifiers count');
check(array_is_list($ids), 'identifiers are a list');
$names = $b->getFrameNames();
check(is_array($names), 'frame names is array');
check(in_array('kid', $names, true), 'frame names contain the iframe name: ' . json_encode($names));
$kid = $b->getFrameByName('kid');
check($kid !== null, 'getFrameByName kid');
if ($kid) {
    same(false, $kid->isMain(), 'kid is not main');
    same('kid', $kid->getName(), 'kid name');
    check($kid->getParent()?->isMain() === true, 'kid parent is main');
    wait_until(fn() => $kid->getURL() === BASE . '/kid.html', 'kid url');
    same(BASE . '/kid.html', $kid->getURL(), 'kid url');
    $kv = new Collect();
    $kid->getText($kv);
    wait_until(fn() => $kv->text !== null, 'kid text');
    same('kid frame text', trim((string)$kv->text), 'kid frame text');
    check($b->getFrameByIdentifier($kid->getIdentifier())?->getName() === 'kid', 'getFrameByIdentifier kid');
}

// history
$n = $c->load->mainEnds;
$b->goBack();
wait_until(fn() => $c->load->mainEnds > $n, 'goBack load');
same(BASE . '/index.html', $b->getMainFrame()->getURL(), 'url after goBack');
same(true, $b->canGoForward(), 'canGoForward after back');
same(false, $b->canGoBack(), 'canGoBack false after back');
$n = $c->load->mainEnds;
$b->goForward();
wait_until(fn() => $c->load->mainEnds > $n, 'goForward load');
same(BASE . '/two.html', $b->getMainFrame()->getURL(), 'url after goForward');

// reload hits the server again
$hits = count(array_filter($GLOBALS['site_hits'], fn($p) => $p === '/two.html'));
$n = $c->load->mainEnds;
$b->reload();
wait_until(fn() => $c->load->mainEnds > $n, 'reload load');
$hits2 = count(array_filter($GLOBALS['site_hits'], fn($p) => $p === '/two.html'));
check($hits2 > $hits, 'reload requested the page again');
$n = $c->load->mainEnds;
$b->reloadIgnoreCache();
wait_until(fn() => $c->load->mainEnds > $n, 'reloadIgnoreCache load');
check(true, 'reloadIgnoreCache');
same(false, $b->isLoading(), 'not loading after load end');
$b->stopLoad();
check(true, 'stopLoad does not crash');

// executeJavaScript
$f = $b->getMainFrame();
$f->executeJavaScript('document.title = "js:" + (6*7)', '', 0);
$f->executeJavaScript('document.body.setAttribute("data-x", "42")', 'test.js', 1);
$s = new Collect();
wait_until(function () use ($f, &$s) {
    $s = new Collect();
    $f->getSource($s);
    for ($i = 0; $i < 50 && $s->text === null; $i++) { Cefloom\Cef::doMessageLoopWork(); Cefloom\Cef::wait(2); }
    return $s->text !== null && str_contains($s->text, 'data-x');
}, 'JS effect visible in the source');

// loadRequest with a POST
$req = Request::create();
$req->setURL(BASE . '/post');
$req->setMethod('POST');
$req->setHeaderByName('X-Test', 'yes', true);
$n = $c->load->mainEnds;
$b->getMainFrame()->loadRequest($req);
wait_until(fn() => $c->load->mainEnds > $n, 'loadRequest load');
same(BASE . '/post', $b->getMainFrame()->getURL(), 'url after loadRequest');

// invalid frame after navigation away of an iframe
$k = $kid ?? null;
unset($kid, $k, $f, $kv);

unset($b, $s, $v, $t);
shutdown_cef($c);
finish();
