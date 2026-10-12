<?php
// Cookies: CookieManager (set, visit, delete, flush), the cookies a server sets and the ones a request sends, a request context
// of its own with a separate cookie jar, and the Cookie struct.
require __DIR__ . '/support.php';

use Cefloom\{Cef, Cookie, CookieManager, CookieVisitor, DeleteCookiesCallback, RequestContext, RequestContextSettings, SetCookieCallback, CompletionCallback};
use Cefloom\Types\{CookieSameSite, URLRequestFlags};

// A URLRequest sends and stores cookies only with this flag (it is the "credentials" of the request).
const WITH_COOKIES = URLRequestFlags::ALLOW_STORED_CREDENTIALS;

final class Collect extends CookieVisitor
{
    public array $cookies = [];
    public array $counts = [];
    public function __construct(private array $delete = [], private bool $stopAfterFirst = false) {}
    public function visit(Cookie $cookie, int $count, int $total): array
    {
        $this->cookies[$cookie->name] = $cookie;
        $this->counts[] = [$count, $total];
        // [continue visiting, delete this cookie]
        return [!$this->stopAfterFirst, in_array($cookie->name, $this->delete, true)];
    }
}
final class SetDone extends SetCookieCallback
{
    public ?bool $ok = null;
    public function onComplete(bool $success): void { $this->ok = $success; }
}
final class DeleteDone extends DeleteCookiesCallback
{
    public ?int $deleted = null;
    public function onComplete(int $numDeleted): void { $this->deleted = $numDeleted; }
}
final class Done extends CompletionCallback
{
    public bool $done = false;
    public function onComplete(): void { $this->done = true; }
}

$base = start_http_server();
start_cef();

/** All cookies of a manager, by name; visiting is asynchronous, so wait until the visitor has seen the last one. */
function jar(CookieManager $manager, array $delete = []): array
{
    $visitor = new Collect($delete);
    if (!$manager->visitAllCookies($visitor)) {
        return [];
    }
    $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline) {
        Cef::doMessageLoopWork();
        $last = end($visitor->counts);
        if ($last !== false && $last[0] === $last[1] - 1) {
            break;
        }
        Cef::wait(5);
    }
    return $visitor->cookies;
}
function set_cookie(CookieManager $manager, string $url, Cookie $cookie): ?bool
{
    $done = new SetDone();
    if (!$manager->setCookie($url, $cookie, $done)) {
        return false;
    }
    wait_until(fn() => $done->ok !== null, 'setCookie completed', 5000);
    return $done->ok;
}

$manager = CookieManager::getGlobalManager(null);
check($manager !== null, 'there is a global cookie manager');
same([], jar($manager), 'it starts empty');

// ---- set a cookie and read it back
$expires = new DateTimeImmutable('+1 day');
$cookie = new Cookie('session', 'abc', '127.0.0.1', '/', false, true, null, null, true, $expires, CookieSameSite::LAX_MODE);
same(true, set_cookie($manager, $base . '/', $cookie), 'setCookie succeeds');
$seen = jar($manager);
same(['session'], array_keys($seen), 'the jar has the cookie');
$got = $seen['session'];
same(['abc', '127.0.0.1', '/'], [$got->value, $got->domain, $got->path], 'its value, domain and path');
check($got->httponly, 'httponly is kept');
check(!$got->secure, 'secure is not set');
same(CookieSameSite::LAX_MODE, $got->sameSite, 'the SameSite mode is kept');
check($got->hasExpires && $got->expires instanceof DateTimeImmutable && abs($got->expires->getTimestamp() - $expires->getTimestamp()) <= 1, 'the expiry date is kept');
check($got->creation instanceof DateTimeImmutable, 'and the creation time is set');

// ---- visitUrlCookies sees the ones that match a URL
set_cookie($manager, $base . '/', new Cookie('other', '2', '127.0.0.1', '/sub', false, false, null, null, false, null));
$v = new Collect();
check($manager->visitUrlCookies($base . '/sub/page', true, $v), 'visitUrlCookies');
wait_until(fn() => $v->counts && end($v->counts)[0] === end($v->counts)[1] - 1, 'visited', 3000);
$names = array_keys($v->cookies);
sort($names);
same(['other', 'session'], $names, 'under /sub both cookies match');
$v = new Collect();
$manager->visitUrlCookies($base . '/elsewhere', true, $v);
wait_until(fn() => $v->counts !== [], 'visited', 3000);
same(['session'], array_keys($v->cookies), 'under /elsewhere only the one with path / matches');
$v = new Collect();
$manager->visitUrlCookies($base . '/', false, $v);
pump(0.3);
check(!isset($v->cookies['session']), 'httponly cookies are left out when includeHttpOnly is false');

// ---- a cookie that the server sets, and one that a request sends
$f = fetch_url("$base/cookie/set?name=fromserver&value=hello&maxage=600", 'GET', [], null, null, WITH_COOKIES);
same(200, $f->httpStatus, 'the server sets a cookie');
$jarNow = jar($manager);
same('hello', $jarNow['fromserver']->value ?? null, 'it is in the jar');
$f = fetch_url("$base/cookie/echo", 'GET', [], null, null, WITH_COOKIES);
check(str_contains($f->body, 'fromserver=hello') && str_contains($f->body, 'session=abc'), 'and the next request sends the cookies: ' . $f->body);
$f = fetch_url("$base/cookie/echo", 'GET', [], null, null, 0);
check(!str_contains($f->body, 'fromserver'), 'a request without the flag sends no cookies');

// ---- delete
$deleted = new DeleteDone();
check($manager->deleteCookies($base . '/', 'fromserver', $deleted), 'deleteCookies');
wait_until(fn() => $deleted->deleted !== null, 'deleted', 5000);
same(1, $deleted->deleted, 'one cookie was deleted');
$f = fetch_url("$base/cookie/echo", 'GET', ['Cache-Control' => 'no-cache'], null, null, WITH_COOKIES);
check(!str_contains($f->body, 'fromserver'), 'the next request does not send it');
$deleted = new DeleteDone();
$manager->deleteCookies($base . '/', '', $deleted);
wait_until(fn() => $deleted->deleted !== null, 'deleted all', 5000);
check($deleted->deleted >= 1, 'an empty name deletes every cookie of the URL (' . $deleted->deleted . ')');
same([], array_keys(jar($manager)), 'the jar is empty');

// ---- a visitor can delete the cookies it visits
set_cookie($manager, $base . '/', new Cookie('keep', '1', '127.0.0.1', '/', false, false, null, null, false, null));
set_cookie($manager, $base . '/', new Cookie('drop', '2', '127.0.0.1', '/', false, false, null, null, false, null));
jar($manager, ['drop']);
pump(0.3);
same(['keep'], array_keys(jar($manager)), 'a visitor that answers "delete" removes that cookie');

// ---- flushing
$flushed = new Done();
check($manager->flushStore($flushed), 'flushStore');
wait_until(fn() => $flushed->done, 'flushed', 5000);

// ---- an invalid cookie is refused
same(false, set_cookie($manager, 'not a url', new Cookie('a', 'b', 'x', '/', false, false, null, null, false, null)), 'and so is a cookie for a URL that is not one');

// ---- a request context of its own has its own jar
$settings = new RequestContextSettings();
$context = RequestContext::createContext($settings, null);
check($context !== null && !$context->isGlobal(), 'a context of our own is not the global one');
$mine = $context->getCookieManager(null);
set_cookie($mine, $base . '/', new Cookie('private', 'p', '127.0.0.1', '/', false, false, null, null, false, null));
same(['private'], array_keys(jar($mine)), 'its jar has its cookie');
check(!isset(jar($manager)['private']), 'and the global jar does not');
$f = fetch_url("$base/cookie/echo", 'GET', [], null, null, WITH_COOKIES, $context);
check(str_contains($f->body, 'private=p') && !str_contains($f->body, 'keep='), 'a request made in that context sends that jar: ' . $f->body);

Cef::shutdown();
finish();
