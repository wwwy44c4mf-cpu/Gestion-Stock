<?php

declare(strict_types=1);

if (($argv[1] ?? '') === 'worker') {
    $modified = @filemtime('/tmp/worker-heartbeat');
    exit($modified !== false && time() - $modified >= 0 && time() - $modified < 120 ? 0 : 1);
}

if (($argv[1] ?? '') === 'fpm') {
    // Interroger FPM lui-même, et non un serveur HTTP distinct.
    $process = proc_open(['cgi-fcgi', '-bind', '-connect', '127.0.0.1:9000'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        ['SCRIPT_NAME' => '/fpm-ping', 'SCRIPT_FILENAME' => '/fpm-ping', 'REQUEST_METHOD' => 'GET']);
    if (!is_resource($process)) {
        exit(1);
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    exit($code === 0 && str_contains($output, 'pong') ? 0 : 1);
}

exit(1);
