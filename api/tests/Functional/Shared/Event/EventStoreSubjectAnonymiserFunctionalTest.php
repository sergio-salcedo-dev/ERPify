<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Event;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Event\Infrastructure\Persistence\DbalEventStoreSubjectAnonymiser;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the business-log erasure against REAL Postgres. Everything load-bearing about this statement lives
 * in the database and not in PHP — the `jsonb → text → jsonb` round trip, the case folding a `uuid` column
 * does and a jsonb one does not, the `CAST(… AS UUID)`, the affected-row count — so a double can model none
 * of it, and the acceptance scenario that exercises it end to end is one line away from being deleted.
 *
 * Three claims the scenario cannot make are made here. That the erasure is bounded to the identifier it was
 * given: rows belonging to somebody else come out byte-identical, which is what stops one subject's erasure
 * from being another's. That it is idempotent for the reason claimed rather than by luck — a second pass is
 * run, not reasoned about. And that both `Uuid::ensure()` guards fire before the driver sees anything: they
 * are the whole safety argument for interpolating either value into a regular expression, and without a test
 * they are indistinguishable from the comment that says they are not one.
 *
 * The counterpart claim is here too, deliberately: handed an identifier that denotes no person, the statement
 * rewrites that aggregate's stream just as willingly. That is not a defect of the adapter — matching by value
 * is what lets it reach an event under a key nobody enumerated — it is why the bound belongs to the caller,
 * and asserting it here is what keeps the caller's guard from looking redundant.
 *
 * Each test runs inside a rolled-back transaction, so the shared dev DB is left as it was found.
 *
 * @internal
 */
#[CoversClass(DbalEventStoreSubjectAnonymiser::class)]
final class EventStoreSubjectAnonymiserFunctionalTest extends KernelTestCase
{
    private const string SUBJECT_ID = '0190f400-0000-7000-8000-0000000000c1';

    private const string OTHER_SUBJECT_ID = '0190f400-0000-7000-8000-0000000000c2';

    private const string INVITATION_ID = '0190f400-0000-7000-8000-0000000000c3';

    private const string BANK_ID = '0190f400-0000-7000-8000-0000000000c4';

    #[Test]
    public function itRewritesEveryAxisAndLeavesAnotherSubjectsRowsByteIdentical(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $anonymiser = new DbalEventStoreSubjectAnonymiser($connection);
            $pseudonym = Uuid::generate();

            // The column axis: an identity event whose aggregate IS the person.
            $identityEvent = $this->seed($connection, self::SUBJECT_ID, 'Iam.Identity', 'identity.suspended', '{}');
            // The payload axis, and the case trap with it: the aggregate is somebody else's invitation, the
            // person's id sits inside the JSON, UPPERCASED and under a key no registry enumerates. `payload`
            // is jsonb TEXT, the one place Postgres does not fold case for us.
            $invitationEvent = $this->seed(
                $connection,
                self::INVITATION_ID,
                'Iam.Invitation',
                'invitation.created',
                \sprintf('{"invitedUserId": "%s"}', \strtoupper(self::SUBJECT_ID)),
            );
            // A second person, untouched by this erasure — one subject's erasure is not another's.
            $otherEvent = $this->seed(
                $connection,
                self::OTHER_SUBJECT_ID,
                'Iam.Identity',
                'identity.suspended',
                \sprintf('{"invitedUserId": "%s"}', self::OTHER_SUBJECT_ID),
            );

            $this->assertSame(2, $anonymiser->anonymise(self::SUBJECT_ID, $pseudonym), 'one row per axis');

            $this->assertSame($pseudonym, $this->columnOf($connection, $identityEvent, 'aggregate_id'));
            // The guarantee is not bought by deleting history: the row survives with its stream position.
            $this->assertSame('1', $this->columnOf($connection, $identityEvent, 'aggregate_version'));

            $invitationAggregate = $this->columnOf($connection, $invitationEvent, 'aggregate_id');
            $this->assertSame(self::INVITATION_ID, $invitationAggregate, 'the column is not its axis');
            $invitationPayload = $this->columnOf($connection, $invitationEvent, 'payload');
            $this->assertStringContainsStringIgnoringCase($pseudonym, $invitationPayload);
            $this->assertStringNotContainsStringIgnoringCase(self::SUBJECT_ID, $invitationPayload);
            // Rewritten, not emptied — the key and the shape a deserializer depends on are still there.
            $this->assertStringContainsString('invitedUserId', $invitationPayload);

            $this->assertSame(self::OTHER_SUBJECT_ID, $this->columnOf($connection, $otherEvent, 'aggregate_id'));
            $this->assertStringContainsStringIgnoringCase(
                self::OTHER_SUBJECT_ID,
                $this->columnOf($connection, $otherEvent, 'payload'),
            );
        });
    }

    #[Test]
    public function itReachesAnAggregateThatIsNotAPersonBecauseTheBoundBelongsToTheCaller(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $bankEvent = $this->seed($connection, self::BANK_ID, 'Backoffice.Bank', 'bank.updated', '{}');
            $accountEvent = $this->seed(
                $connection,
                Uuid::generate(),
                'Backoffice.BankAccount',
                'bank_account.created',
                \sprintf('{"bankId": "%s"}', self::BANK_ID),
            );

            $affected = (new DbalEventStoreSubjectAnonymiser($connection))
                ->anonymise(self::BANK_ID, Uuid::generate())
            ;

            // Two rows an erasure had no business touching. Nothing in this adapter could have told, which is
            // the reason `FulfilIdentityErasure` runs it only for a subject whose identity was live.
            $this->assertSame(2, $affected);
            $this->assertNotSame(self::BANK_ID, $this->columnOf($connection, $bankEvent, 'aggregate_id'));
            $this->assertStringNotContainsStringIgnoringCase(
                self::BANK_ID,
                $this->columnOf($connection, $accountEvent, 'payload'),
            );
        });
    }

    #[Test]
    public function itIsIdempotentBecauseTheValueItSearchedForIsGone(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $this->seed($connection, self::SUBJECT_ID, 'Iam.Identity', 'identity.suspended', '{}');

            $anonymiser = new DbalEventStoreSubjectAnonymiser($connection);

            $this->assertSame(1, $anonymiser->anonymise(self::SUBJECT_ID, Uuid::generate()));
            $this->assertSame(0, $anonymiser->anonymise(self::SUBJECT_ID, Uuid::generate()), 'a re-run finds none');
        });
    }

    #[Test]
    public function itRefusesAMalformedSubjectBeforeReachingTheDriver(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $this->expectException(InvalidUuidException::class);

            // Interpolated into a regular expression and into a `LIKE` pattern: `%` and `.` would both be read
            // as syntax. The guard is what makes that interpolation safe, so it has to be the thing that fires.
            (new DbalEventStoreSubjectAnonymiser($connection))->anonymise('%.*%', Uuid::generate());
        });
    }

    #[Test]
    public function itRefusesAMalformedPseudonymBeforeReachingTheDriver(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $this->expectException(InvalidUuidException::class);

            // The replacement string has its own syntax in Postgres — `\1`…`\9` and `\&` — so it needs the
            // same guard as the pattern, and for a different reason.
            (new DbalEventStoreSubjectAnonymiser($connection))->anonymise(self::SUBJECT_ID, '\1');
        });
    }

    private function seed(
        Connection $connection,
        string $aggregateId,
        string $aggregateType,
        string $eventName,
        string $payload,
    ): string {
        $eventId = Uuid::generate();

        $connection->executeStatement(
            'INSERT INTO event_store (event_id, aggregate_id, aggregate_type, aggregate_version, event_name, '
            . 'event_version, payload, metadata, tenant_id, occurred_on, recorded_on) '
            . 'VALUES (CAST(:event_id AS UUID), CAST(:aggregate_id AS UUID), :aggregate_type, 1, :event_name, '
            . "1, CAST(:payload AS JSONB), CAST('[]' AS JSONB), NULL, :occurred_on, :occurred_on)",
            [
                'event_id' => $eventId,
                'aggregate_id' => $aggregateId,
                'aggregate_type' => $aggregateType,
                'event_name' => \sprintf('erpify.%s', $eventName),
                'payload' => $payload,
                'occurred_on' => '2026-03-01 12:00:00+00',
            ],
        );

        return $eventId;
    }

    /**
     * One column of one row, rendered by Postgres rather than by the driver: `::text` is the canonical
     * serialisation of every type read here, so the assertions compare what is stored instead of whatever
     * shape the driver chose to hand back for it.
     */
    private function columnOf(Connection $connection, string $eventId, string $column): string
    {
        $value = $connection->fetchOne(
            \sprintf('SELECT %s::text FROM event_store WHERE event_id = CAST(:id AS UUID)', $column),
            ['id' => $eventId],
        );
        $this->assertIsString($value);

        return $value;
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
