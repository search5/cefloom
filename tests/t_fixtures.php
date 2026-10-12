<?php
// The local HTTP server of the tests (tests/fixtures/server.php) serves what the network tests need. Nothing of CEF here.
require __DIR__ . '/support.php';

$base = start_http_server();

/** GET or POST with the headers of the response: [status, headers (lower case names), body]. */
function http(string $url, array $headers = [], ?string $body = null): array
{
    $context = stream_context_create(['http' => [
        'method' => $body === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
        'header' => implode("\r\n", $headers), 'content' => $body ?? '',
    ]]);
    $text = @file_get_contents($url, false, $context);
    $status = 0;
    $map = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) {
            $status = (int) $m[1];
        } elseif (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $map[strtolower($k)][] = trim($v);
        }
    }
    return [$status, $map, (string) $text];
}

[$st, , $body] = http("$base/hello");
same([200, 'hello'], [$st, $body], '/hello');
[$st, , $body] = http("$base/status/418");
same([418, 'status 418'], [$st, $body], '/status/<code>');
[$st, $h] = http("$base/redirect?to=/hello&code=301");
same([301, ['/hello']], [$st, $h['location'] ?? null], '/redirect');
[, $h] = http("$base/cookie/set?name=session&value=abc&maxage=60&httponly=1");
$cookie = implode(';', $h['set-cookie'] ?? []);
check(str_contains($cookie, 'session=abc') && stripos($cookie, 'httponly') !== false && stripos($cookie, 'expires') !== false, '/cookie/set sets a cookie');
[, , $body] = http("$base/cookie/echo", ['Cookie: a=1; b=2']);
same('a=1; b=2', $body, '/cookie/echo');
[$st, $h] = http("$base/auth");
same([401, true], [$st, str_contains(implode('', $h['www-authenticate'] ?? []), 'Basic')], '/auth asks for credentials');
[$st, $h] = http("$base/a9/auth?realm=other");
same([401, true], [$st, str_contains(implode('', $h['www-authenticate'] ?? []), 'realm="other"')], '/<dir>/auth?realm=R names the realm');
[$st, , $body] = http("$base/auth", ['Authorization: Basic ' . base64_encode('u:p')]);
same([200, 'authorized as u'], [$st, $body], '/auth accepts u:p');
[$st] = http("$base/auth", ['Authorization: Basic ' . base64_encode('u:wrong')]);
same(401, $st, '/auth refuses a wrong password');
[, $h, $body] = http("$base/download?size=2500");
same([2500, true], [strlen($body), str_contains(implode('', $h['content-disposition'] ?? []), 'file.bin')], '/download');
[, , $body] = http("$base/big?size=300000");
same(300000, strlen($body), '/big');
[$st, $h, $body] = http("$base/range", ['Range: bytes=100-199']);
same([206, 100, ['bytes 100-199/10000']], [$st, strlen($body), $h['content-range'] ?? null], '/range answers a Range request');
[$st, , $body] = http("$base/range");
same([200, 10000], [$st, strlen($body)], '/range without Range');
$t = microtime(true);
http("$base/slow?ms=300");
check(microtime(true) - $t >= 0.29, '/slow waits');
[, , $body] = http("$base/headers", ['X-Test: yes']);
check(($j = json_decode($body, true)) && ($j['X-Test'] ?? '') === 'yes', '/headers echoes the headers');
[, , $body] = http("$base/post", [], 'payload');
same('payload', $body, '/post echoes the body');
[, $h, $body] = http("$base/page?title=Hi&body=" . rawurlencode('<p>x</p>'));
check(str_contains($body, '<title>Hi</title>') && str_contains($body, '<p>x</p>') && str_contains(implode('', $h['content-type'] ?? []), 'text/html'), '/page');
foreach (['/script.js' => 'text/javascript', '/style.css' => 'text/css', '/image.png' => 'image/png'] as $path => $type) {
    [$st, $h, $body] = http($base . $path);
    check($st === 200 && str_contains(implode('', $h['content-type'] ?? []), $type) && $body !== '', "$path");
}
[$st] = http("$base/nothing-here");
same(404, $st, 'an unknown path is 404');

// the workers answer several requests at the same time
$sockets = [];
$t = microtime(true);
for ($i = 0; $i < 6; $i++) {
    $s = stream_socket_client(substr($base, 7), $errno, $errstr, 5);
    fwrite($s, "GET /slow?ms=400 HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
    $sockets[] = $s;
}
$done = 0;
foreach ($sockets as $s) {
    $done += str_contains((string) stream_get_contents($s), 'slow') ? 1 : 0;
    fclose($s);
}
$elapsed = microtime(true) - $t;
same(6, $done, 'six parallel requests are answered');
check($elapsed < 1.8, "they are served in parallel ($elapsed s; one after another would take 2.4 s)");

finish();
