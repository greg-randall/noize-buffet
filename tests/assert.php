<?php
declare(strict_types=1);

$GLOBALS['__checks'] = ['pass' => 0, 'fail' => 0];

function check(bool $cond, string $msg): void
{
    $GLOBALS['__checks'][$cond ? 'pass' : 'fail']++;
    echo ($cond ? '  ok    ' : '  FAIL  ') . $msg . "\n";
}

function tmp_dir(): string
{
    $dir = __DIR__ . '/tmp';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

/** A fresh, empty test database; also exported as NB_DB for child processes. */
function fresh_db(string $name): PDO
{
    $path = tmp_dir() . "/$name.sqlite";
    @unlink($path);
    putenv("NB_DB=$path");
    return nb_db($path);
}

function finish(): never
{
    $c = $GLOBALS['__checks'];
    echo $c['fail'] === 0 ? "ALL PASSED ({$c['pass']})\n" : "FAILED {$c['fail']} of " . ($c['pass'] + $c['fail']) . "\n";
    exit($c['fail'] === 0 ? 0 : 1);
}
