<?php

declare(strict_types=1);

// A scripted stand-in for clamd's TCP protocol, run as a child process by FakeClamd. It prints the port it listens
// on (and exits after a minute with no connection, so a test that dies leaves nothing behind), then answers each
// connection: PING with PONG, INSTREAM with the reply its mode names after reading the whole
// stream (or none, for 'drop'). Not production code: it exists so ClamdScanner is tested against a real socket.

$mode = $argv[1] ?? 'ok';
$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

if (false === $server) {
    fwrite(STDERR, "cannot listen: {$errorMessage}\n");
    exit(1);
}

$name = stream_socket_get_name($server, false);
fwrite(STDOUT, substr((string) strrchr((string) $name, ':'), 1) . "\n");
fflush(STDOUT);

$replies = [
    'ok' => "stream: OK\0",
    'found' => "stream: Win.Test.EICAR_HDB-1 FOUND\0",
    'limit-found' => "stream: Heuristics.Limits.Exceeded.MaxFileSize FOUND\0",
    'size' => "INSTREAM size limit exceeded. ERROR\0",
    'error' => "stream: lstat() failed. ERROR\0",
];

while (true) {
    $connection = @stream_socket_accept($server, 60);

    if (false === $connection) {
        exit(0);
    }

    $command = '';

    while (!str_ends_with($command, "\0")) {
        $byte = fread($connection, 1);

        if (false === $byte || '' === $byte) {
            break;
        }

        $command .= $byte;
    }

    if ("zPING\0" === $command) {
        fwrite($connection, "PONG\0");
        fclose($connection);

        continue;
    }

    $received = 0;

    while (true) {
        $header = '';

        while (strlen($header) < 4) {
            $part = fread($connection, 4 - strlen($header));

            if (false === $part || '' === $part) {
                break 2;
            }

            $header .= $part;
        }

        /** @var array{1: int} $length */
        $length = unpack('N', $header);

        // Past its ceiling clamd answers and closes at once, without reading the rest of the stream.
        if ('size-close' === $mode) {
            fwrite($connection, $replies['size']);
            fclose($connection);

            continue 2;
        }

        if (0 === $length[1]) {
            break;
        }

        $left = $length[1];

        while ($left > 0) {
            $part = fread($connection, $left);

            if (false === $part || '' === $part) {
                break 3;
            }

            $left -= strlen($part);
            $received += strlen($part);
        }
    }

    if ('slow' === $mode) {
        sleep(3);
    }

    if ('drop' !== $mode) {
        fwrite($connection, $replies[$mode] ?? $replies['ok']);
    }

    fclose($connection);
}
