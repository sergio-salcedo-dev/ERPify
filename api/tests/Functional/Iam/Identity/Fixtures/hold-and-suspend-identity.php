<?php

declare(strict_types=1);

/*
 * Plays a suspension racing a login from a SEPARATE PROCESS: it writes `status = 'SUSPENDED'` on one
 * `identity_user` row inside an open transaction — the plain `UPDATE` a status change performs, holding the
 * row's `FOR NO KEY UPDATE` lock — says so on stdout, keeps the transaction open for the given number of
 * microseconds and commits. A second connection in the test's own process cannot do this: the login under test
 * blocks on that lock, so nothing in its process could ever commit the write it is waiting on.
 *
 * Usage: php hold-and-suspend-identity.php <subject-id> <hold-microseconds>, with the connection parameters as
 * one JSON object on stdin, so the password never appears in the process list. It exits 3, having written
 * nothing, when the server it reaches is not serving a test database.
 */

use Doctrine\DBAL\DriverManager;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
$subjectId = is_array($arguments) ? ($arguments[1] ?? null) : null;
$holdMicroseconds = is_array($arguments) ? ($arguments[2] ?? null) : null;

if (!is_string($subjectId) || !is_string($holdMicroseconds) || !ctype_digit($holdMicroseconds)) {
    fwrite(STDERR, "usage: hold-and-suspend-identity.php <subject-id> <hold-microseconds>\n");

    exit(2);
}

$params = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

if (!is_array($params)) {
    fwrite(STDERR, "expected the connection parameters as a JSON object on stdin\n");

    exit(2);
}

/** @var array{driver: 'pdo_pgsql', host?: string, port?: int, user?: string, password?: string} $params */
$connection = DriverManager::getConnection($params);

// Asked of the server, never read from the parameters: a run that resolved the runtime database would
// otherwise suspend a developer's identity there.
$database = $connection->fetchOne('SELECT current_database()');

if (!is_string($database) || !str_contains($database, '_test')) {
    fwrite(STDERR, "refusing to write anything outside a test database\n");

    exit(3);
}

$connection->beginTransaction();
$suspended = $connection->executeStatement(
    "UPDATE identity_user SET status = 'SUSPENDED', updated_at = NOW() WHERE id = CAST(:id AS UUID)",
    ['id' => $subjectId],
);

fwrite(STDOUT, (1 === $suspended ? 'locked' : 'absent') . "\n");
fflush(STDOUT);

usleep((int) $holdMicroseconds);

$connection->commit();

fwrite(STDOUT, "suspended {$suspended}\n");
