<?php
// URLRequest and URLRequestClient against the local server: bodies, status, headers, POST with upload progress, redirects,
// credentials, cancel, failures, and the flags that skip the cache or the cookies.
require __DIR__ . '/support.php';

use Cefloom\Cef;
use Cefloom\Types\{ErrorCode, URLRequestFlags, URLRequestStatus};

$base = start_http_server();
start_cef();

// ---- a plain GET
$f = fetch_url("$base/hello");
same([URLRequestStatus::SUCCESS, 200, 'hello'], [$f->status, $f->httpStatus, $f->body], 'GET: success, 200 and the body');
same(ErrorCode::NONE, $f->error, 'and no error');
check($f->headers !== [], 'the response headers are there: ' . json_encode($f->headers));
check($f->down && end($f->down)[0] === 5, 'download progress reaches the size of the body');

// ---- status codes
foreach ([404, 500, 204] as $code) {
    $f = fetch_url("$base/status/$code");
    same([URLRequestStatus::SUCCESS, $code], [$f->status, $f->httpStatus], "an HTTP $code is a successful request with that status");
}

// ---- a big body arrives in pieces and is complete
$f = fetch_url("$base/big?size=300000");
same([URLRequestStatus::SUCCESS, 300000], [$f->status, strlen($f->body)], 'a 300 KB body arrives complete');
check(count($f->down) >= 1 && end($f->down)[0] === 300000, 'with progress up to its size');

// ---- request headers and method
$f = fetch_url("$base/headers", 'GET', ['X-Test' => 'cefloom']);
check(str_contains(strtolower($f->body), 'x-test') && str_contains($f->body, 'cefloom'), 'a request header reaches the server');

// ---- POST with a body
$f = fetch_url("$base/post", 'POST', ['Content-Type' => 'text/plain'], 'payload-123', null, URLRequestFlags::REPORT_UPLOAD_PROGRESS);
same(200, $f->httpStatus, 'POST succeeds');
check(str_contains($f->body, 'payload-123'), 'and the server got the body: ' . substr($f->body, 0, 80));
check($f->up !== [] && end($f->up)[0] === 11, 'with REPORT_UPLOAD_PROGRESS, upload progress reaches the size of the body');

// ---- redirects
$f = fetch_url("$base/redirect?to=/hello&code=302");
same([200, 'hello'], [$f->httpStatus, $f->body], 'a redirect is followed');
$f = fetch_url("$base/redirect?to=/hello&code=302", 'GET', [], null, null, URLRequestFlags::STOP_ON_REDIRECT);
check($f->status === URLRequestStatus::CANCELED || $f->httpStatus === 302, 'STOP_ON_REDIRECT stops at the redirect (' . var_export($f->status, true) . ', ' . var_export($f->httpStatus, true) . ')');

// ---- credentials
$f = fetch_url("$base/b1/auth?realm=basic", 'GET', [], null, ['u', 'p'], URLRequestFlags::ALLOW_STORED_CREDENTIALS);
same([200, 'authorized as u'], [$f->httpStatus, $f->body], 'credentials given to getAuthCredentials are used');
same(['basic'], array_column($f->asked, 2), 'the realm is told');
same(false, $f->asked[0][0] ?? null, 'and that it is not a proxy');
$f = fetch_url("$base/b2/auth?realm=wrong", 'GET', [], null, ['u', 'bad'], URLRequestFlags::ALLOW_STORED_CREDENTIALS);
same([401, 3], [$f->httpStatus, count($f->asked)], 'wrong credentials: the client is asked again after each 401 (twice answered, the third time it gives up), and the 401 is the answer');
$f = fetch_url("$base/b3/auth?realm=none", 'GET', [], null, null, URLRequestFlags::ALLOW_STORED_CREDENTIALS);
$g = fetch_url("$base/b4/auth?realm=flagless", 'GET', [], null, ['u', 'p']);
same([401, 0], [$g->httpStatus, count($g->asked)], 'without ALLOW_STORED_CREDENTIALS a 401 is passed on and the client is not asked');
same([401, 1], [$f->httpStatus, count($f->asked)], 'a client that declines (returns false) leaves the 401');

// ---- cancel
[$request, $f] = start_fetch("$base/slow?ms=3000");
$request->cancel();
wait_until(fn() => $f->done, 'the canceled request completed', 10000);
same(URLRequestStatus::CANCELED, $f->status, 'cancel(): the status is CANCELED');
same(ErrorCode::ABORTED, $f->error, 'and the error is ABORTED');

// ---- failures
$f = fetch_url('http://127.0.0.1:1/');
same([URLRequestStatus::FAILED, true], [$f->status, $f->error !== ErrorCode::NONE], 'a refused connection fails with an error');
$f = fetch_url('http://nonexistent.invalid/');
same(URLRequestStatus::FAILED, $f->status, 'an unknown host fails');

// ---- the getters of the request while it is running
[$request, $f] = start_fetch("$base/hello");
same("$base/hello", $request->getRequest()->getURL(), 'getRequest()->getURL()');
check($request->getClient() === $f, 'getClient() is the client that was given');
wait_until(fn() => $f->done, 'done');
same(URLRequestStatus::SUCCESS, $request->getRequestStatus(), 'getRequestStatus() after the end');

// ---- the cache
$a = fetch_url("$base/hello");
$b = fetch_url("$base/hello", 'GET', [], null, null, URLRequestFlags::ONLY_FROM_CACHE);
check($b->status === URLRequestStatus::SUCCESS || $b->status === URLRequestStatus::FAILED, 'ONLY_FROM_CACHE gives a result');
$c = fetch_url("$base/hello", 'GET', [], null, null, URLRequestFlags::SKIP_CACHE);
same(false, $c->cached, 'SKIP_CACHE: not from the cache');

Cef::shutdown();
finish();
