<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Event;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Event\Application\SubjectPseudonym;
use Erpify\Shared\Event\Application\SubjectPseudonymisation;
use Erpify\Shared\Event\Infrastructure\Persistence\DbalEventStoreSubjectAnonymiser;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The erasure's indexed predicate selects exactly the rows a text match over the serialised row selects. The contract
 * is `col::text ILIKE '%id%'` ⇔ `event_store_uuids(col) @> ARRAY[CAST(id AS uuid)]` for every valid id, and it
 * is checked here as a SET equality over an adversarial corpus, per probe, in both directions — the indexed
 * form missing a row would leave a person's id behind an erasure that reported success, and matching an extra
 * one would rewrite somebody else's history.
 *
 * Two levels, because they fail differently. The first compares the two predicates alone, per JSON column, so
 * a red names the column and the probe. The second runs the adapter's real statement and compares the rows it
 * actually rewrote with what the reference predicate selects over the whole row (`aggregate_id` included),
 * which is what keeps this test about the production SQL rather than about a copy of it kept here.
 *
 * The corpus is the shapes a text match catches and a naive UUID extractor would not: the id under a nested
 * value, as a KEY, upper-cased, glued to hex and non-hex neighbours, overlapping another UUID-shaped window,
 * in `metadata`, in `aggregate_id`; near-misses that must match nothing; and the document's root, which is an
 * object or the legacy empty list `[]` — the only roots the table admits (`event_store_*_is_object`), so a
 * scalar or a non-empty list as root is no longer a shape the predicate can meet; multibyte text glued to the
 * id, the id in both JSON columns of one row and several times in one document, and a long hex/dash run
 * holding it, which is the shape a scan restarting from the start of the text pays for quadratically. The
 * overlap row is there because an extractor resuming after a match, like `regexp_matches(…, 'g')` over the UUID
 * pattern, never sees a UUID starting inside the previous one.
 *
 * Every probe runs inside a rolled-back transaction.
 *
 * @internal
 */
#[CoversClass(DbalEventStoreSubjectAnonymiser::class)]
final class EventStoreSubjectPredicateEquivalenceFunctionalTest extends KernelTestCase
{
    private const string SUBJECT = '0190f400-0000-7000-8000-0000000000d1';

    private const string OTHER = '0190f400-0000-7000-8000-0000000000d2';

    /** Ends where SUBJECT begins, so two UUID-shaped windows overlap; a valid v4 id once completed (below). */
    private const string OVERLAP_PREFIX = '11111111-2222-4333-8444-5555';

    private const string OVERLAP_HEAD = '11111111-2222-4333-8444-55550190f400';

    private const string TEXT_PREDICATE = 'aggregate_id = CAST(:id AS UUID) '
        . 'OR payload::text ILIKE :pattern OR metadata::text ILIKE :pattern';

    #[Test]
    #[DataProvider('probes')]
    public function theIndexedPredicateSelectsWhatTheTextMatchSelectsInEachColumn(string $probe): void
    {
        $this->inRolledBackTransaction(function (Connection $connection) use ($probe): void {
            $corpus = $this->seedCorpus($connection);

            foreach (['payload', 'metadata'] as $column) {
                $byText = $this->select($connection, $corpus, \sprintf('%s::text ILIKE :pattern', $column), $probe);
                $byIndex = $this->select(
                    $connection,
                    $corpus,
                    \sprintf('event_store_uuids(%s) @> ARRAY[CAST(:id AS UUID)]', $column),
                    $probe,
                );

                $this->assertSame(
                    $byText,
                    $byIndex,
                    \sprintf('`%s`: the indexed predicate and ILIKE disagree for probe %s', $column, $probe),
                );
            }
        });
    }

    #[Test]
    #[DataProvider('probes')]
    public function theStatementRewritesExactlyTheRowsTheTextMatchSelects(string $probe): void
    {
        $this->inRolledBackTransaction(function (Connection $connection) use ($probe): void {
            $corpus = $this->seedCorpus($connection);
            $expected = $this->select($connection, $corpus, self::TEXT_PREDICATE, $probe);
            $before = $this->snapshot($connection, $corpus);

            $affected = (new DbalEventStoreSubjectAnonymiser($connection))->anonymise(
                SubjectPseudonymisation::of(
                    subjectId: $probe,
                    pseudonym: SubjectPseudonym::fromString(Uuid::generate()),
                ),
            );

            $after = $this->snapshot($connection, $corpus);
            $rewritten = \array_keys(\array_diff_assoc($before, $after));
            \sort($rewritten);

            $this->assertSame($expected, $rewritten, \sprintf('rows rewritten for probe %s', $probe));
            $this->assertSame(\count($expected), $affected);
        });
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function probes(): iterable
    {
        yield 'the subject' => [self::SUBJECT];
        yield 'the subject, upper-cased' => [\strtoupper(self::SUBJECT)];
        yield 'another subject' => [self::OTHER];
        yield 'the window the overlapping subject starts inside' => [self::OVERLAP_HEAD];
        yield 'an id nobody holds' => ['0190f400-0000-7000-8000-0000000000ff'];
    }

    /**
     * The fixed points the per-probe comparisons hang on: an equality between two empty sets would pass over
     * a corpus that seeded nothing, so the shapes the subject must be found in are counted here.
     */
    #[Test]
    public function theCorpusPlacesTheSubjectWhereTheMatrixSaysItIs(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $corpus = $this->seedCorpus($connection);

            $selected = $this->select($connection, $corpus, self::TEXT_PREDICATE, self::SUBJECT);
            $expected = \array_keys(\array_filter($corpus, static fn (bool $holdsSubject): bool => $holdsSubject));
            \sort($expected);

            $this->assertSame($expected, $selected);
            $this->assertCount(15, $selected);
        });
    }

    /**
     * @return array<string, bool> event id => whether the row holds SUBJECT
     */
    private function seedCorpus(Connection $connection): array
    {
        $subject = self::SUBJECT;
        $upper = \strtoupper(self::SUBJECT);
        $other = self::OTHER;
        $nearMissShort = \substr(self::SUBJECT, 0, 35);
        $nearMissHyphen = '0190f40-00000-7000-8000-0000000000d1';

        $rows = [
            // [aggregate_id, payload, metadata, holds SUBJECT]
            [$other, \sprintf('{"a": {"b": [1, {"c": "%s"}]}}', $subject), '{}', true],
            [$other, \sprintf('{"%s": true}', $subject), '{}', true],
            [$other, \sprintf('{"userId": "%s"}', $upper), '{}', true],
            [$other, \sprintf('{"ref": "abc%s123"}', $subject), '{}', true],
            [$other, \sprintf('{"ref": "xyz-%s-tail"}', $subject), '{}', true],
            [$other, \sprintf('{"ref": "%s%s"}', self::OVERLAP_PREFIX, $subject), '{}', true],
            [$other, '{}', \sprintf('{"actor": "%s"}', $subject), true],
            [$subject, '{}', '{}', true],
            [$other, \sprintf('{"pair": ["%s", "%s"]}', $upper, $other), '[]', true],
            [$other, '{}', \sprintf('{"%s": {"k": "%s"}}', $other, $upper), true],
            [$other, \sprintf('{"ids": "%s,%s"}', $other, $subject), '{}', true],
            [$other, \sprintf('{"note": "café ☕ ñandú%s"}', $subject), '{}', true],
            [$other, \sprintf('{"userId": "%s"}', $subject), \sprintf('{"actor": "%s"}', $upper), true],
            [$other, \sprintf('{"a": "%1$s", "b": ["%1$s", "%2$s"], "%1$s": "%1$s"}', $subject, $upper), '{}', true],
            [
                $other,
                \sprintf(
                    '{"run": "%s%s%s"}',
                    \str_repeat('0123456789abcdef-', 3000),
                    $subject,
                    \str_repeat('-fedcba98', 2000),
                ),
                '{}',
                true,
            ],
            [$other, \sprintf('{"ref": "%s"}', $nearMissShort), '{}', false],
            [$other, \sprintf('{"ref": "%s"}', $nearMissHyphen), '{}', false],
            [$other, \sprintf('{"userId": "%s"}', $other), '{}', false],
            [$other, '[]', '{}', false],
        ];

        $corpus = [];

        foreach ($rows as [$aggregateId, $payload, $metadata, $holdsSubject]) {
            $eventId = Uuid::generate();
            $connection->executeStatement(
                'INSERT INTO event_store (event_id, aggregate_id, aggregate_type, aggregate_version, event_name, '
                . 'event_version, payload, metadata, tenant_id, occurred_on, recorded_on) '
                . 'VALUES (CAST(:event_id AS UUID), CAST(:aggregate_id AS UUID), :type, 1, :name, 1, '
                . 'CAST(:payload AS JSONB), CAST(:metadata AS JSONB), NULL, :occurred_on, :occurred_on)',
                [
                    'event_id' => $eventId,
                    'aggregate_id' => $aggregateId,
                    'type' => 'Test.Equivalence',
                    'name' => 'erpify.test.equivalence',
                    'payload' => $payload,
                    'metadata' => $metadata,
                    'occurred_on' => '2026-03-01 12:00:00+00',
                ],
            );
            $corpus[$eventId] = $holdsSubject;
        }

        return $corpus;
    }

    /**
     * @param array<string, bool> $corpus
     *
     * @return list<string> the corpus event ids the predicate selects, sorted
     */
    private function select(Connection $connection, array $corpus, string $predicate, string $probe): array
    {
        $parameters = ['corpus' => \array_keys($corpus)];
        $types = ['corpus' => ArrayParameterType::STRING];

        if (\str_contains($predicate, ':id')) {
            $parameters['id'] = $probe;
        }

        if (\str_contains($predicate, ':pattern')) {
            $parameters['pattern'] = '%' . $probe . '%';
        }

        $ids = $connection->fetchFirstColumn(
            \sprintf(
                'SELECT event_id::text FROM event_store WHERE event_id::text IN (:corpus) AND (%s) ORDER BY 1',
                $predicate,
            ),
            $parameters,
            $types,
        );

        return \array_map(static fn (mixed $id): string => \is_string($id) ? $id : '', $ids);
    }

    /**
     * @param array<string, bool> $corpus
     *
     * @return array<string, string> event id => the row's three erasable columns as Postgres renders them
     */
    private function snapshot(Connection $connection, array $corpus): array
    {
        $rows = $connection->fetchAllKeyValue(
            "SELECT event_id::text, aggregate_id::text || '|' || payload::text || '|' || metadata::text "
            . 'FROM event_store WHERE event_id::text IN (:corpus)',
            ['corpus' => \array_keys($corpus)],
            ['corpus' => ArrayParameterType::STRING],
        );

        $snapshot = [];

        foreach ($rows as $id => $row) {
            $this->assertIsString($row);
            $snapshot[(string) $id] = $row;
        }

        return $snapshot;
    }

    private function inRolledBackTransaction(callable $body): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $body($connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
