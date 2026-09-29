<?php

declare(strict_types=1);

/*
 * Plays an erasure's hold on one `identity_user` row from a SEPARATE PROCESS: it takes the row under
 * `SELECT … FOR UPDATE`, says so on stdout, keeps holding it for the given number of microseconds, deletes it
 * and commits. A second connection in the test's own process cannot do this — the test is blocked inside the
 * writer while the row is held, so nothing in that process could ever commit the delete it is waiting on.
 *
 * Usage: php hold-and-erase-identity.php <subject-id> <hold-microseconds>, with the connection parameters as
 * one JSON object on stdin, so the password never appears in the process list.
 */

use Doctrine\DBAL\DriverManager;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
$subjectId = is_array($arguments) ? ($arguments[1] ?? null) : null;
$holdMicroseconds = is_array($arguments) ? ($arguments[2] ?? null) : null;

if (!is_string($subjectId) || !is_string($holdMicroseconds) || !ctype_digit($holdMicroseconds)) {
    fwrite(STDERR, "usage: hold-and-erase-identity.php <subject-id> <hold-microseconds>\n");

    exit(2);
}

$params = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

if (!is_array($params)) {
    fwrite(STDERR, "expected the connection parameters as a JSON object on stdin\n");

    exit(2);
}

/** @var array{driver: 'pdo_pgsql', host?: string, port?: int, user?: string, password?: string} $params */
$connection = DriverManager::getConnection($params);
$connection->beginTransaction();
$locked = $connection->fetchOne(
    'SELECT id FROM identity_user WHERE id = CAST(:id AS UUID) FOR UPDATE',
    ['id' => $subjectId],
);

fwrite(STDOUT, (false === $locked ? 'absent' : 'locked') . "\n");
fflush(STDOUT);

usleep((int) $holdMicroseconds);

$deleted = $connection->executeStatement(
    'DELETE FROM identity_user WHERE id = CAST(:id AS UUID)',
    ['id' => $subjectId],
);
$connection->commit();

fwrite(STDOUT, "deleted {$deleted}\n");
