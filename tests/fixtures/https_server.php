<?php
// A tiny HTTPS server for the SSL tests: php https_server.php <port> <cert.pem> <key.pem> [require-client-cert]. It answers every request with
// "hello over tls" and, for /who, with the subject of the client certificate it was given. Stops when its parent goes away or is killed.
[$script, $port, $cert, $key] = $argv;
$requireClient = ($argv[4] ?? '') === 'require-client-cert';
$options = ['local_cert' => $cert, 'local_pk' => $key, 'allow_self_signed' => true, 'verify_peer' => false, 'verify_peer_name' => false];
if ($requireClient) {
    $options['verify_peer'] = true;
    $options['cafile'] = $cert;                  // the client certificate is signed by the same (self-signed) authority
    $options['capture_peer_cert'] = true;
}
$context = stream_context_create(['ssl' => $options]);
$server = stream_socket_server("ssl://127.0.0.1:$port", $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$server) {
    fwrite(STDERR, "cannot listen: $error\n");
    exit(1);
}
echo "ready\n";
$parent = getmypid();
while (true) {
    $read = [$server];
    $write = $except = null;
    if (@stream_select($read, $write, $except, 1) < 1) {
        continue;
    }
    $client = @stream_socket_accept($server, 5);
    if (!$client) {
        continue;
    }
    $request = '';
    while (!str_contains($request, "\r\n\r\n") && strlen($request) < 8192) {
        $chunk = @fread($client, 2048);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $request .= $chunk;
    }
    $body = "hello over tls";
    if (str_contains($request, 'GET /who')) {
        $peer = stream_context_get_options($context)['ssl']['peer_certificate'] ?? null;
        $body = $peer ? 'client: ' . (openssl_x509_parse($peer)['subject']['CN'] ?? '?') : 'no client certificate';
    }
    @fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n$body");
    @fclose($client);
}
