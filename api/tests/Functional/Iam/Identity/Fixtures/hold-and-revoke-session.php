<?php

declare(strict_types=1);

/*
 * Plays a session revocation's transaction from a SEPARATE PROCESS, statement for statement the shape
 * `RevokeSession` gives it: the session row under `SELECT … FOR UPDATE` (admissible rows only), the status flip,
 * and the `SessionRevoked` append naming the session's user — then it says so on stdout, keeps the transaction
 * open for the given number of microseconds and commits. The test's own process cannot play this part: it is
 * blocked inside the erasure while the row is held, so nothing in it could ever commit the revocation the
 * erasure is waiting on.
 *
 * Usage: php hold-and-revoke-session.php <session-id> <user-id> <event-id> <hold-microseconds>, with the
 * connection parameters as one JSON object on stdin, so the password never appears in the process list. It
 * exits 3, having locked and written nothing, when the server it reaches is not serving a test database.
 */

use Doctrine\DBAL\DriverManager;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
$sessionId = is_array($arguments) ? ($arguments[1] ?? null) : null;
$userId = is_array($arguments) ? ($arguments[2] ?? null) : null;
$eventId = is_array($arguments) ? ($arguments[3] ?? null) : null;
$holdMicroseconds = is_array($arguments) ? ($arguments[4] ?? null) : null;

if (!is_string($sessionId) || !is_string($userId) || !is_string($eventId)) {
    fwrite(STDERR, "usage: hold-and-revoke-session.php <session-id> <user-id> <event-id> <hold-microseconds>\n");

    exit(2);
}

if (!is_string($holdMicroseconds) || !ctype_digit($holdMicroseconds)) {
    fwrite(STDERR, "usage: hold-and-revoke-session.php <session-id> <user-id> <event-id> <hold-microseconds>\n");

    exit(2);
}

$params = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

if (!is_array($params)) {
    fwrite(STDERR, "expected the connection parameters as a JSON object on stdin\n");

    exit(2);
}

/** @var array{driver: 'pdo_pgsql', host?: string, port?: int, user?: string, password?: string} $params */
$connection = DriverManager::getConnection($params);

// This script writes to the business log, so it asks the server which database it reached before it touches
// anything; the name is asked of the server, never read from the parameters it was given.
$database = $connection->fetchOne('SELECT current_database()');

if (!is_string($database) || !str_contains($database, '_test')) {
    fwrite(STDERR, "refusing to hold or write anything outside a test database\n");

    exit(3);
}

$connection->beginTransaction();
$locked = $connection->fetchOne(
    "SELECT id FROM iam_session WHERE id = CAST(:id AS UUID) AND status = 'ACTIVE' FOR UPDATE",
    ['id' => $sessionId],
);

if (false === $locked) {
    $connection->rollBack();
    fwrite(STDOUT, "absent\n");

    exit(0);
}

$connection->executeStatement(
    "UPDATE iam_session SET status = 'REVOKED', revoked_at = clock_timestamp() WHERE id = CAST(:id AS UUID)",
    ['id' => $sessionId],
);
$connection->executeStatement(
    'INSERT INTO event_store (event_id, aggregate_id, aggregate_type, aggregate_version, event_name, '
    . 'event_version, payload, metadata, tenant_id, occurred_on, recorded_on) '
    . "VALUES (CAST(:event_id AS UUID), CAST(:session_id AS UUID), 'Iam.Session', 2, "
    . "'erpify.iam.session.revoked', 1, CAST(:payload AS JSONB), CAST('{}' AS JSONB), NULL, "
    . 'clock_timestamp(), clock_timestamp())',
    [
        'event_id' => $eventId,
        'session_id' => $sessionId,
        'payload' => json_encode(['userId' => $userId], JSON_THROW_ON_ERROR),
    ],
);

fwrite(STDOUT, "locked\n");
fflush(STDOUT);

usleep((int) $holdMicroseconds);

$connection->commit();

fwrite(STDOUT, "committed\n");
