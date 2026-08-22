<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Event\Infrastructure\Messenger\DbalFailedMessagePruner;
use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\PhpSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Static gate over the one statement that deletes from `messenger_messages`, for the two properties of it a
 * behavioural test either cannot reach or cannot reach from inside the repository.
 *
 * **`FOR UPDATE`** is the first. `ORDER BY id` alone buys nothing: `LIMIT` blocks sublink pull-up, so the
 * outer `DELETE` unique-ifies the subquery through a blocking node that discards its order and probes in that
 * node's order. The lock is what makes the ordering describe acquisition rather than scanning. Nothing in the
 * functional suite goes red on deleting the clause — lock order is only observable against a concurrent
 * contender, and here that contender is the transport's own consumer, which no test drives. What proves
 * `LockRows` lands where the argument needs it is `FailedMessagePrunePlanFunctionalTest`, against real
 * Postgres; this gate proves the clause is still written.
 *
 * **The queue name is the second, and it is the one with teeth.** `async` and `failed` are one physical
 * table discriminated by `queue_name`, so the constant this pruner filters on has to keep meaning what
 * `config/packages/messenger.yaml` says the failure transport writes. A DSN edit that renames the queue does
 * not break a test anywhere — it makes the pruner match zero rows, for ever, in the safe direction, which is
 * precisely the silent no-op that a green suite cannot tell from a working control.
 *
 * **What a green proves:** `src` deletes from this table in one statement; that statement still carries the
 * ordering, the lock and the queue predicate; and the queue it names is the one the failure transport is
 * configured to write. Not that the prune runs, and not that any caller reaches it.
 *
 * @internal
 */
#[CoversNothing]
final class FailedMessagePruneStatementGateTest extends TestCase
{
    private const string PRUNER = 'Shared/Event/Infrastructure/Messenger/DbalFailedMessagePruner.php';

    private const string TABLE = 'messenger_messages';

    #[Test]
    public function theBatchingSelectIsOrderedAndLocked(): void
    {
        $this->assertMatchesRegularExpression(
            '/ORDER\s+BY\s+id\s+LIMIT\s+:batch\s+FOR\s+UPDATE/',
            $this->prunerSource(),
            'The prune no longer batches as `… ORDER BY id LIMIT :batch FOR UPDATE`. The lock is the half '
            . 'that is easy to lose: without it the ordered scan takes no locks at all and the outer DELETE '
            . 're-locks in whatever order the node that unique-ified the subquery produced, which is what '
            . 'puts a drain head-on against the transport consumer holding its own row locks.',
        );
    }

    #[Test]
    public function theBatchingSelectIsScopedToOneQueue(): void
    {
        $this->assertMatchesRegularExpression(
            '/WHERE\s+queue_name\s*=\s*:queue\s+AND/',
            $this->prunerSource(),
            'The prune no longer scopes itself to a single queue. `async` and `failed` share this table, so '
            . 'a statement without the predicate deletes messages that are in flight — not dead letters '
            . 'early, but live work, with nothing downstream to report their absence.',
        );
    }

    #[Test]
    public function itPrunesTheTableTheFailureTransportActuallyWritesTo(): void
    {
        $this->assertMatchesRegularExpression(
            '/DELETE\s+FROM\s+' . self::TABLE . '\b/i',
            $this->prunerSource(),
            'The pruner no longer deletes from the table this gate pins.',
        );

        $dsn = $this->failureTransportDsn();
        $this->assertStringStartsWith('doctrine://', $dsn, \sprintf(
            'The failure transport is no longer a Doctrine one (%s), so a raw `DELETE` against a table is not '
            . 'what prunes it — the pruner would match zero rows for ever, in the safe direction, with every '
            . 'gate green.',
            $dsn,
        ));

        \parse_str((string) \parse_url($dsn, PHP_URL_QUERY), $query);
        $this->assertSame(self::TABLE, $query['table_name'] ?? self::TABLE, \sprintf(
            'The failure transport writes a table other than `%s`, which the pruner has hard-coded.',
            self::TABLE,
        ));
    }

    #[Test]
    public function theQueueItPrunesIsTheOneTheFailureTransportWrites(): void
    {
        $this->assertSame(
            DbalFailedMessagePruner::FAILED_QUEUE,
            $this->configuredFailureQueue(),
            'The pruner filters on a queue name the failure transport no longer writes. Nothing else '
            . 'notices: the statement stays valid, matches zero rows, and the table grows exactly as it did '
            . 'before the pruner existed.',
        );
    }

    /**
     * Scoped to `src` rather than to the pruner's own file: the property worth holding is that no OTHER
     * production class deletes from this table, since a second delete path is both how the queue predicate
     * gets forgotten and how a concurrent drain ends early. Comments are stripped so the docblocks that
     * explain the statement are not counted as second ones.
     */
    #[Test]
    public function srcDeletesFromTheMessengerTableInExactlyOneStatement(): void
    {
        $deleters = [];

        foreach (ApiSourceFiles::phpFiles() as $file) {
            $source = \file_get_contents($file->getPathname());

            if (!\is_string($source)) {
                continue;
            }

            $deletes = '/DELETE\s+FROM\s+' . self::TABLE . '|TRUNCATE\s+(?:TABLE\s+)?' . self::TABLE
                . '|->\s*delete\s*\(\s*[\'"]' . self::TABLE . '/i';

            if (0 === \preg_match_all($deletes, PhpSource::withoutComments($source))) {
                continue;
            }

            $deleters[] = \substr($file->getPathname(), \strlen(ApiSourceFiles::root()) + 1);
        }

        $this->assertSame([self::PRUNER], $deleters, \sprintf(
            'Exactly one class in src may delete from messenger_messages, and it is the failure-transport '
            . 'pruner. Found: %s. A second delete path is how the `queue_name` predicate goes missing on the '
            . 'copy nobody reviewed, and it also ends a concurrent drain early, because a row deleted under '
            . "the pruner's FOR UPDATE drops out of its batch with no replacement.",
            \implode(', ', $deleters),
        ));
    }

    private function prunerSource(): string
    {
        $source = \file_get_contents(ApiSourceFiles::root() . '/' . self::PRUNER);
        $this->assertIsString($source, 'the pruner is missing from src, so this gate covers nothing');

        return $source;
    }

    /**
     * The queue the failure transport writes, read from the transport definition rather than restated.
     * Messenger accepts it either as a DSN query parameter or under `options`, and this repo uses the first
     * for `failed` and the second for `async` — so both shapes are honoured, and a transport that declares
     * neither fails the gate instead of defaulting.
     */
    private function failureTransportDsn(): string
    {
        $configFile = \dirname(__DIR__, 4) . '/config/packages/messenger.yaml';
        $parsed = Yaml::parseFile($configFile);
        $this->assertIsArray($parsed);

        $messenger = $this->mappingAt($parsed, ['framework', 'messenger']);
        $failureTransport = $messenger['failure_transport'] ?? null;
        $this->assertIsString($failureTransport);

        $transport = $this->mappingAt($parsed, ['framework', 'messenger', 'transports', $failureTransport]);
        $dsn = $transport['dsn'] ?? null;
        $this->assertIsString($dsn, 'the failure transport declares no dsn');

        return $dsn;
    }

    private function configuredFailureQueue(): string
    {
        $configFile = \dirname(__DIR__, 4) . '/config/packages/messenger.yaml';
        $this->assertFileExists($configFile);

        $parsed = Yaml::parseFile($configFile);
        $this->assertIsArray($parsed);

        $messenger = $this->mappingAt($parsed, ['framework', 'messenger']);

        // The transport is resolved through `failure_transport`, never by the literal `failed`: repointing it
        // while the old block survives — dead config routinely outlives the edit that orphaned it — would
        // leave this gate reading a transport nothing writes and calling it agreement.
        $failureTransport = $messenger['failure_transport'] ?? null;
        $this->assertIsString($failureTransport, 'messenger.yaml declares no `failure_transport`.');

        $transport = $this->mappingAt($parsed, ['framework', 'messenger', 'transports', $failureTransport]);

        $options = $transport['options'] ?? null;
        $fromOptions = \is_array($options) ? ($options['queue_name'] ?? null) : null;

        if (\is_string($fromOptions)) {
            return $fromOptions;
        }

        $dsn = $transport['dsn'] ?? null;
        $this->assertIsString($dsn, 'the `failed` transport declares neither a dsn nor an options queue_name');

        \parse_str((string) \parse_url($dsn, PHP_URL_QUERY), $query);
        $queueName = $query['queue_name'] ?? null;
        $this->assertIsString($queueName, 'the `failed` transport dsn carries no queue_name');

        return $queueName;
    }

    /**
     * Walks the config one key at a time so a renamed or restructured level fails HERE, naming the key it
     * lost, rather than surfacing as an unexplained null further down.
     *
     * @param array<mixed> $parsed
     * @param list<string> $path
     *
     * @return array<mixed>
     */
    private function mappingAt(array $parsed, array $path): array
    {
        $cursor = $parsed;

        foreach ($path as $key) {
            $next = $cursor[$key] ?? null;
            $this->assertIsArray($next, \sprintf('messenger.yaml has no `%s` mapping where this gate reads it', $key));
            $cursor = $next;
        }

        return $cursor;
    }
}
