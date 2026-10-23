<?php

declare(strict_types=1);

/*
 * Plays a status write racing a login from a SEPARATE PROCESS. It opens a transaction, writes the given status
 * on one `identity_user` row in one of two shapes, says so on stdout, and then keeps the transaction open until
 * another backend is observed WAITING ON A LOCK THIS ONE HOLDS (`pg_blocking_pids()` naming this process's
 * backend) — or until a bounded timeout — before it commits. Asking who is blocked BY this backend, rather than
 * watching a pid the caller passes in, survives the caller's connection being reopened between requests. A
 * second connection in the test's own process cannot do this: the login blocks on the row this process holds,
 * so nothing in its process could ever commit the write it is waiting on.
 *
 *  - `for-update`: `SELECT … FOR UPDATE`, then `UPDATE` — the shape every status writer here takes.
 *  - `plain`: the `UPDATE` alone, holding only `FOR NO KEY UPDATE` — the shape a writer that skips the explicit
 *    lock takes, and the one `FOR KEY SHARE` does not wait for.
 *
 * Usage: php hold-and-suspend-identity.php <subject-id> <for-update|plain> <status>, with the
 * connection parameters as one JSON object on stdin, so the password never appears in the process list.
 * Prints `locked`, then `waited` or `never-waited`, then `committed <rows>`. Exits 0 once a waiter was seen,
 * 4 when none was (having committed anyway, so the login is never left blocked), and 3, having
 * written nothing, when the server it reaches is not serving a test database.
 */

use Doctrine\DBAL\DriverManager;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

const WAIT_DEADLINE_MICROSECONDS = 10_000_000;
const POLL_MICROSECONDS = 20_000;

$arguments = $_SERVER['argv'] ?? [];
[$subjectId, $shape, $status] = is_array($arguments)
    ? [$arguments[1] ?? null, $arguments[2] ?? null, $arguments[3] ?? null]
    : [null, null, null];

if (!is_string($subjectId) || !in_array($shape, ['for-update', 'plain'], true) || !is_string($status)) {
    fwrite(STDERR, "usage: hold-and-suspend-identity.php <subject-id> <for-update|plain> <status>\n");

    exit(2);
}

$params = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

if (!is_array($params)) {
    fwrite(STDERR, "expected the connection parameters as a JSON object on stdin\n");

    exit(2);
}

/**
 * @var array{
 *     driver: 'pdo_pgsql',
 *     host?: string,
 *     port?: int,
 *     user?: string,
 *     password?: string,
 *     dbname?: string,
 *     charset?: string,
 *     serverVersion?: string,
 * } $params
 */
$connection = DriverManager::getConnection($params);

// Asked of the server, never read from the parameters: a run that resolved the runtime database would
// otherwise rewrite a developer's identity there.
$database = $connection->fetchOne('SELECT current_database()');

if (!is_string($database) || !str_contains($database, '_test')) {
    fwrite(STDERR, "refusing to write anything outside a test database\n");

    exit(3);
}

$connection->beginTransaction();

if ('for-update' === $shape) {
    $connection->fetchOne('SELECT id FROM identity_user WHERE id = CAST(:id AS UUID) FOR UPDATE', ['id' => $subjectId]);
}

$written = $connection->executeStatement(
    'UPDATE identity_user SET status = :status, updated_at = NOW() WHERE id = CAST(:id AS UUID)',
    ['status' => $status, 'id' => $subjectId],
);

fwrite(STDOUT, (1 === $written ? 'locked' : 'absent') . "\n");
fflush(STDOUT);

$waited = false;

for ($elapsed = 0; $elapsed < WAIT_DEADLINE_MICROSECONDS; $elapsed += POLL_MICROSECONDS) {
    // The statistics views are read once per transaction and cached until it ends, and this one stays open by
    // design, so the cache is dropped before every look — otherwise the first answer would be the only one.
    $connection->executeQuery('SELECT pg_stat_clear_snapshot()');
    $blocked = $connection->fetchOne(
        "SELECT COUNT(*) FROM pg_stat_activity WHERE wait_event_type = 'Lock'"
        . ' AND pg_backend_pid() = ANY (pg_blocking_pids(pid))',
    );

    if (is_numeric($blocked) && (int) $blocked > 0) {
        $waited = true;

        break;
    }

    usleep(POLL_MICROSECONDS);
}

fwrite(STDOUT, ($waited ? 'waited' : 'never-waited') . "\n");
fflush(STDOUT);

$connection->commit();

fwrite(STDOUT, "committed {$written}\n");

exit($waited ? 0 : 4);
