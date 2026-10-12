<?php
// The router of the local HTTP server of the tests (php -S 127.0.0.1:<port> fixtures/server.php).
// Everything a test needs from a web server, on localhost only:
//   /hello                        text
//   /status/<code>                that status with a short body
//   /redirect?to=<url>&code=302   a redirect
//   /cookie/set?name=a&value=b    Set-Cookie (&path=/x &maxage=60 &httponly=1 &samesite=Lax)
//   /cookie/echo                  the Cookie header of the request
//   /auth                         Basic auth, user "u" password "p": 401 with a challenge, else "authorized as u"
//   /download?size=N              an attachment of N bytes (default 1000), named file.bin
//   /big?size=N                   N bytes of text
//   /range                        10000 bytes; honours the Range header (206)
//   /slow?ms=N                    answers after N milliseconds
//   /headers                      the request headers as JSON
//   /post                         echoes the body of the request
//   /page?title=T&body=B          a small HTML page
//   /script.js, /style.css, /image.png   small subresources (for requests a page makes itself)
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$q = $_GET;

function body_of(int $n): string
{
    $line = "0123456789abcdef";
    return substr(str_repeat($line, intdiv($n, strlen($line)) + 1), 0, $n);
}

if ($path === '/hello') {
    header('Content-Type: text/plain');
    echo 'hello';
} elseif (preg_match('#^/status/(\d{3})$#', $path, $m)) {
    http_response_code((int) $m[1]);
    header('Content-Type: text/plain');
    echo "status {$m[1]}";
} elseif ($path === '/redirect') {
    http_response_code((int) ($q['code'] ?? 302));
    header('Location: ' . ($q['to'] ?? '/hello'));
} elseif ($path === '/cookie/set') {
    $opts = ['path' => $q['path'] ?? '/', 'httponly' => !empty($q['httponly']), 'samesite' => $q['samesite'] ?? 'Lax'];
    if (isset($q['maxage'])) {
        $opts['expires'] = time() + (int) $q['maxage'];
    }
    setcookie($q['name'] ?? 'a', $q['value'] ?? 'b', $opts);
    header('Content-Type: text/plain');
    echo 'set';
} elseif ($path === '/cookie/echo') {
    header('Content-Type: text/plain');
    echo $_SERVER['HTTP_COOKIE'] ?? '';
} elseif ($path === '/auth') {
    if (($_SERVER['PHP_AUTH_USER'] ?? '') === 'u' && ($_SERVER['PHP_AUTH_PW'] ?? '') === 'p') {
        header('Content-Type: text/plain');
        echo 'authorized as u';
    } else {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="cefloom tests"');
        header('Content-Type: text/plain');
        echo 'unauthorized';
    }
} elseif ($path === '/download') {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="file.bin"');
    echo body_of((int) ($q['size'] ?? 1000));
} elseif ($path === '/big') {
    header('Content-Type: text/plain');
    echo body_of((int) ($q['size'] ?? 1000000));
} elseif ($path === '/range') {
    $all = body_of(10000);
    header('Accept-Ranges: bytes');
    header('Content-Type: text/plain');
    if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'] ?? '', $m)) {
        $from = $m[1] === '' ? 0 : (int) $m[1];
        $to = $m[2] === '' ? strlen($all) - 1 : min((int) $m[2], strlen($all) - 1);
        http_response_code(206);
        header("Content-Range: bytes $from-$to/" . strlen($all));
        echo substr($all, $from, $to - $from + 1);
    } else {
        echo $all;
    }
} elseif ($path === '/slow') {
    usleep((int) ($q['ms'] ?? 500) * 1000);
    header('Content-Type: text/plain');
    echo 'slow';
} elseif ($path === '/headers') {
    header('Content-Type: application/json');
    echo json_encode(getallheaders());
} elseif ($path === '/post') {
    header('Content-Type: text/plain');
    echo file_get_contents('php://input');
} elseif ($path === '/page') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($q['title'] ?? 'page') . '</title></head><body>'
        . ($q['body'] ?? '<h1>page</h1>') . '</body></html>';
} elseif ($path === '/script.js') {
    header('Content-Type: text/javascript');
    echo 'window.scriptLoaded = true;';
} elseif ($path === '/style.css') {
    header('Content-Type: text/css');
    echo 'body { color: #123456; }';
} elseif ($path === '/image.png') {
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
} else {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'not found';
}
