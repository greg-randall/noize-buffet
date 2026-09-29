<?php
declare(strict_types=1);
// The web API with several stations: listing them, making one, and keeping each request to its own station.
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/assert.php';

$dir = tmp_dir() . '/api_stations';
exec('rm -rf ' . escapeshellarg($dir));
mkdir($dir);
putenv("NB_PROFILES_DIR=$dir/profiles");
putenv('NB_DB');
putenv('NB_PROFILE');
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
$json = ['Content-Type' => 'application/json'];
$call = function (string $method, string $query, ?array $body = null) use ($port, $json): array {
    $headers = ['Host' => "localhost:$port"] + ($method === 'POST' ? $json : []);
    $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'content' => $body === null ? '' : json_encode($body),
        'header' => implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers))]]);
    $raw = @file_get_contents("http://127.0.0.1:$port/api.php?$query", false, $ctx);
    preg_match('#^HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    return [(int)($m[1] ?? 0), json_decode((string)$raw, true)];
};

echo "a fresh install\n";
[$code, $r] = $call('GET', 'action=profiles');
check($code === 200 && array_column($r['profiles'], 'slug') === ['main'] && $r['default'] === 'main', 'the first request makes "main"');

echo "making a station\n";
[$code, $r] = $call('POST', 'action=profile_create', ['name' => 'Late night jazz']);
check($code === 200 && $r['slug'] === 'late-night-jazz' && $r['name'] === 'Late night jazz', 'a station is made from its name');
[$code, $r] = $call('POST', 'action=profile_create', ['name' => 'late NIGHT jazz']);
check($code === 400 && str_contains($r['error'], 'already'), 'a name that is already taken is refused');
[$code, $r] = $call('POST', 'action=profile_create', ['name' => '???']);
check($code === 400, 'so is a name with nothing usable in it');
[$code] = $call('GET', 'action=profile_create');
check($code === 405, 'making one needs a POST');
[$code, $r] = $call('GET', 'action=profiles');
check(array_column($r['profiles'], 'name') === ['Late night jazz', 'Main'], 'both are listed');

echo "each request is for one station\n";
$call('POST', 'action=send&profile=late-night-jazz', ['message' => 'a jazz message']);
$call('POST', 'action=send&profile=main', ['message' => 'a main message']);
$chat = fn(string $slug) => array_column($call('GET', "action=chat&after=0&profile=$slug")[1]['messages'], 'text');
check(in_array('a jazz message', $chat('late-night-jazz'), true) && !in_array('a main message', $chat('late-night-jazz'), true),
    "a message sent to one station is in that station's chat");
check(in_array('a main message', $chat('main'), true) && !in_array('a jazz message', $chat('main'), true), 'and not in the other');
[$code, $r] = $call('GET', 'action=queue&profile=nope');
check($code === 404 && $r['error'] === 'unknown station', 'a station that does not exist is a 404');
[$code] = $call('GET', 'action=queue&profile=' . urlencode('../x'));
check($code === 404, 'and a path is not a station');
[$code] = $call('GET', 'action=queue');
check($code === 200, 'with no station given, the first one is used');
$call('POST', 'action=start&profile=late-night-jazz', []);
$jobs = function (string $slug): int {
    nb_profile($slug);
    return (int)nb_db()->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
};
check($jobs('late-night-jazz') >= 1, "the interview starts in the station that asked");

proc_terminate($server);
proc_close($server);
finish();
