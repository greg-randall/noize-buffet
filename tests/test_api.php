<?php
declare(strict_types=1);
// The web API only answers this page: other websites (cross-site requests) and other host names (DNS rebinding) are
// refused, and POSTs must be JSON (a cross-site form or text/plain POST needs no CORS check, so it is refused).
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/assert.php';

fresh_db('test_api'); // exports NB_DB for the server
$port = 0;
for ($try = 0; $try < 20 && $port === 0; $try++) {
    $p = random_int(20000, 40000);
    $s = @stream_socket_server("tcp://127.0.0.1:$p");
    if ($s) {
        fclose($s);
        $port = $p;
    }
}
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', __DIR__ . '/../player'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}

/** One request; returns [HTTP status, decoded JSON body]. */
$call = function (string $method, string $action, array $headers = [], ?string $body = null) use ($port): array {
    $headers += ['Host' => "localhost:$port"];
    $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'content' => $body ?? '',
        'header' => implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers))]]);
    $raw = @file_get_contents("http://127.0.0.1:$port/api.php?action=$action", false, $ctx);
    preg_match('#^HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    return [(int)($m[1] ?? 0), json_decode((string)$raw, true)];
};
$json = ['Content-Type' => 'application/json'];
$msg = json_encode(['message' => 'hello']);

[$code, $body] = $call('GET', 'queue');
check($code === 200 && $body === [], 'the page can read the queue');
[$code] = $call('POST', 'send', $json + ['Origin' => "http://localhost:$port"], $msg);
check($code === 200, 'the page can send a chat message (JSON, its own Origin)');
[$code] = $call('POST', 'send', $json, $msg);
check($code === 200, 'a JSON POST with no Origin (e.g. curl) is fine');
[$code] = $call('POST', 'send', ['Content-Type' => 'text/plain'], $msg);
check($code === 415, 'a text/plain POST (what a cross-site page can send without a CORS check) is refused');
[$code] = $call('POST', 'send', ['Content-Type' => 'application/x-www-form-urlencoded'], $msg);
check($code === 415, 'a form POST is refused');
[$code] = $call('POST', 'send', $json + ['Origin' => 'https://evil.example'], $msg);
check($code === 403, 'another website is refused');
[$code] = $call('POST', 'send', $json + ['Origin' => 'http://localhost:1'], $msg);
check($code === 403, 'another port on localhost is another site too');
[$code] = $call('GET', 'queue', ['Host' => 'evil.example']);
check($code === 403, 'a request for another host name (DNS rebinding) is refused, reads included');
[$code] = $call('GET', 'queue', ['Host' => "127.0.0.1:$port"]);
check($code === 200, '127.0.0.1 is fine');
[$code] = $call('GET', 'queue', ['Host' => "[::1]:$port"]);
check($code === 200, '[::1] is fine');
[$code] = $call('GET', 'queue', ['Host' => "[::1]:$port", 'Origin' => "http://[::1]:$port"]);
check($code === 200, 'an IPv6 Origin matching its Host is fine');
$pdo = nb_db();
check(count(nb_chat_since($pdo, 0)) === 2, 'only the two allowed messages reached the chat');

proc_terminate($server);
proc_close($server);
finish();
