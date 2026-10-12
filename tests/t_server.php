<?php
// Server and ServerHandler: an HTTP server inside the PHP process (CEF's own), answering from PHP callbacks. The client is a
// separate PHP process (the callbacks need this process's message loop to run). Also a WebSocket exchange.
require __DIR__ . '/support.php';

use Cefloom\{Callback, Cef, Request, Server, ServerHandler};

final class Handler extends ServerHandler
{
    public ?Server $server = null;
    public array $log = [];
    public array $requests = [];
    public array $messages = [];
    public bool $destroyed = false;
    public function onServerCreated(Server $server): void { $this->server = $server; $this->log[] = 'created'; }
    public function onServerDestroyed(Server $server): void { $this->destroyed = true; $this->log[] = 'destroyed'; }
    public function onClientConnected(Server $server, int $connectionId): void { $this->log[] = 'connected'; }
    public function onClientDisconnected(Server $server, int $connectionId): void { $this->log[] = 'disconnected'; }
    public function onHttpRequest(Server $server, int $connectionId, string $clientAddress, Request $request): void
    {
        $url = $request->getURL();
        $path = parse_url($url, PHP_URL_PATH);
        $this->requests[] = [$request->getMethod(), $path, $clientAddress, $request->getHeaderByName('X-Probe')];
        switch ($path) {
            case '/hello':
                $server->sendHttp200Response($connectionId, 'text/plain', 'hello from php');
                break;
            case '/boom':
                $server->sendHttp500Response($connectionId, 'it broke');
                break;
            case '/custom':
                $body = '{"ok":true}';
                $server->sendHttpResponse($connectionId, 201, 'application/json', strlen($body), ['X-Extra' => 'yes']);
                $server->sendRawData($connectionId, $body);
                $server->closeConnection($connectionId);
                break;
            default:
                $server->sendHttp404Response($connectionId);
        }
    }
    public function onWebSocketRequest(Server $server, int $connectionId, string $clientAddress, Request $request, Callback $callback): void
    {
        $this->log[] = 'ws-request:' . parse_url($request->getURL(), PHP_URL_PATH);
        $callback->continue();
    }
    public function onWebSocketConnected(Server $server, int $connectionId): void { $this->log[] = 'ws-connected'; }
    public function onWebSocketMessage(Server $server, int $connectionId, \Cefloom\Buffer $data): void
    {
        $text = $data->read(0, $data->getSize());
        $this->messages[] = $text;
        $server->sendWebSocketMessage($connectionId, 'echo:' . $text);
    }
}

/** A free port on 127.0.0.1. */
function free_port(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
    fclose($s);
    return $port;
}

/** Run a PHP snippet in a child process; returns a handle whose output is read later (the loop of this process must keep running). */
function child(string $code): array
{
    $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return [$proc, $pipes];
}
function child_output(array $c, int $timeoutMs = 15000): string
{
    $out = '';
    $deadline = microtime(true) + $timeoutMs / 1000;
    while (microtime(true) < $deadline) {
        Cef::doMessageLoopWork();
        $out .= (string) stream_get_contents($c[1][1]);
        $status = proc_get_status($c[0]);
        if (!$status['running']) {
            $out .= (string) stream_get_contents($c[1][1]);
            break;
        }
        Cef::wait(5);
    }
    $err = (string) stream_get_contents($c[1][2]);
    proc_close($c[0]);
    return $out . ($err !== '' ? "\n[stderr] $err" : '');
}
function http_get(int $port, string $path, string $method = 'GET'): array
{
    $code = '$s = stream_context_create(["http" => ["method" => ' . var_export($method, true) . ', "ignore_errors" => true, "timeout" => 10, "header" => "X-Probe: yes\r\n"]]);'
        . '$b = file_get_contents("http://127.0.0.1:' . $port . $path . '", false, $s);'
        . 'echo json_encode([$http_response_header[0] ?? "", $http_response_header, $b]);';
    return child($code);
}

start_cef();
$port = free_port();
$handler = new Handler();
Server::create('127.0.0.1', $port, 5, $handler);        // the server itself arrives in onServerCreated
wait_until(fn() => $handler->server !== null, 'the server was created');
$server = $handler->server;
check($server instanceof Server && $handler->log === ['created'], 'Server::create calls onServerCreated with the server');
same(false, $server->isRunning(), 'isRunning is false when asked on the PHP thread (CEF answers it on the server thread only: K35)');
same("127.0.0.1:$port", $server->getAddress(), 'getAddress');
check($server->getTaskRunner() !== null, 'the server has a task runner');
same(false, $server->hasConnection(), 'no connection yet');

// ---- 200
$c = http_get($port, '/hello');
$result = json_decode(child_output($c), true);
same('HTTP/1.1 200 OK', $result[0] ?? null, 'sendHttp200Response: the status line');
same('hello from php', $result[2] ?? null, 'and the body');
check(str_contains(strtolower(str_replace(' ', '', implode("\n", $result[1] ?? []))), 'content-type:text/plain'), 'and the content type: ' . json_encode($result[1] ?? null));
same(['GET', '/hello', 'yes'], [$handler->requests[0][0] ?? null, $handler->requests[0][1] ?? null, $handler->requests[0][3] ?? null], 'the handler saw the method, the path and a header of the request');
check(str_starts_with($handler->requests[0][2] ?? '', '127.0.0.1:'), 'and the address of the client: ' . ($handler->requests[0][2] ?? ''));
check(in_array('connected', $handler->log, true), 'onClientConnected was called');

// ---- 404, 500, custom
$result = json_decode(child_output(http_get($port, '/nothing')), true);
same('HTTP/1.1 404 Not Found', $result[0] ?? null, 'sendHttp404Response');
$result = json_decode(child_output(http_get($port, '/boom')), true);
same(['HTTP/1.1 500 Internal Server Error', 'it broke'], [$result[0] ?? null, $result[2] ?? null], 'sendHttp500Response with its message');
$result = json_decode(child_output(http_get($port, '/custom')), true);
same(['HTTP/1.1 201 Created', '{"ok":true}'], [$result[0] ?? null, $result[2] ?? null], 'sendHttpResponse with sendRawData: the status and the body');
check(str_contains(strtolower(str_replace(' ', '', implode("\n", $result[1] ?? []))), 'x-extra:yes'), 'and the extra header: ' . json_encode($result[1] ?? null));
$result = json_decode(child_output(http_get($port, '/hello', 'POST')), true);
same('POST', $handler->requests[count($handler->requests) - 1][0] ?? null, 'a POST arrives as a POST');
pump(0.3);
check(in_array('disconnected', $handler->log, true), 'onClientDisconnected was called');

// ---- a WebSocket
$wsCode = <<<'PHP'
$s = stream_socket_client("tcp://127.0.0.1:PORT", $e, $m, 5);
$key = base64_encode(random_bytes(16));
fwrite($s, "GET /socket HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
stream_set_timeout($s, 5);
$head = "";
while (!str_contains($head, "\r\n\r\n")) { $head .= fread($s, 1); }
$out = [strtok($head, "\r\n")];
$payload = "ping";
$mask = random_bytes(4);
$masked = "";
for ($i = 0; $i < strlen($payload); $i++) { $masked .= $payload[$i] ^ $mask[$i % 4]; }
fwrite($s, chr(0x81) . chr(0x80 | strlen($payload)) . $mask . $masked);
$h = fread($s, 2);
$len = ord($h[1]) & 0x7f;
$out[] = ord($h[0]);
$out[] = fread($s, $len);
echo json_encode($out);
PHP;
$c = child(str_replace('PORT', (string) $port, $wsCode));
$ws = json_decode(child_output($c), true);
check(str_starts_with($ws[0] ?? '', 'HTTP/1.1 101'), 'a WebSocket upgrade is answered with 101: ' . ($ws[0] ?? ''));
same([0x81, 'echo:ping'], [$ws[1] ?? null, $ws[2] ?? null], 'sendWebSocketMessage: the client gets the echo of its text frame');
same(['ping'], $handler->messages, 'onWebSocketMessage got the message');
check(in_array('ws-request:/socket', $handler->log, true) && in_array('ws-connected', $handler->log, true), 'onWebSocketRequest (continued) and onWebSocketConnected were called: ' . implode(',', $handler->log));

// ---- shutdown
$server->shutdown();
wait_until(fn() => $handler->destroyed, 'the server was destroyed', 5000);
$c = http_get($port, '/hello');
$result = child_output($c);
check(!str_contains($result, '200 OK'), 'and does not answer any more');

Cef::shutdown();
finish();
