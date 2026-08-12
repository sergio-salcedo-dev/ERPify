<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditResourceAnonymiser;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the resource-axis erasure against REAL Postgres — the `resource_type`/`resource_id` pair matching
 * and the `CAST(… AS UUID)` an in-memory double cannot model.
 *
 * The load-bearing assertions are the **two directions of the column asymmetry**, and they are separate
 * tests because they fail for opposite reasons. Over-reach: a row naming the subject was very often written
 * by somebody else (an administrator acting on them), so `actor_id`, `actor_erased` and — where an actor was
 * identified — `ip`/`user_agent` must come out untouched, or a third party's evidence is destroyed and that
 * party is falsely flagged as erased. Under-reach: where `actor_type` is `anonymous` the row records no
 * discriminant for whose metadata it holds — it may be the subject's own — and leaving it is how that
 * address outlives the erasure meant to forget them, unreachable by any control once `resource_erased` is
 * raised.
 *
 * Each test runs inside a rolled-back transaction, so the shared dev DB is left as it was found.
 *
 * @internal
 */
#[CoversClass(DbalAuditResourceAnonymiser::class)]
final class AuditResourceAnonymiserFunctionalTest extends KernelTestCase
{
    private const string SUBJECT_ID = '0190f300-0000-7000-8000-0000000000b1';

    private const string OTHER_SUBJECT_ID = '0190f300-0000-7000-8000-0000000000b2';

    private const string ADMIN_ID = '0190f300-0000-7000-8000-0000000000b3';

    private const string BANK_ID = '0190f300-0000-7000-8000-0000000000b4';

    private const string CLIENT_IP = '203.0.113.7';

    private const string SYSTEM_IP = '203.0.113.9';

    private const string USER_AGENT = 'Mozilla/5.0';

    /**
     * Spelled out rather than read from {@see \Erpify\Shared\Audit\Domain\AuditRedaction}: the ADR asserts
     * the compliance invariant over this literal, and a test that reads the constant would pass unchanged if
     * somebody changed what the constant holds. The sibling actor-axis test pins it the same way.
     */
    private const string SENTINEL = '[REDACTED]';

    private const string PERSON_TYPE = 'User';

    #[Test]
    public function itRewritesOnlyTheMatchingResourceAndLeavesEveryActorColumnAlone(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $writer = new DbalAuditLogWriter($connection);
            $pseudonym = Uuid::generate();

            // The crosswalk row: the subject acted on themselves, so both columns name the same person.
            $subject = ActorContext::forUser(self::SUBJECT_ID);
            $admin = ActorContext::forUser(self::ADMIN_ID);
            $selfActed = $this->seed($writer, $subject, self::PERSON_TYPE, self::SUBJECT_ID);
            // An administrator acted on the subject — the subject is the resource, the admin is the actor.
            $adminActed = $this->seed($writer, $admin, self::PERSON_TYPE, self::SUBJECT_ID);
            // Same subject as actor, but a non-person resource: out of this axis' reach.
            $bankRow = $this->seed($writer, $subject, 'Bank', self::BANK_ID);
            // A different person entirely.
            $otherRow = $this->seed($writer, $admin, self::PERSON_TYPE, self::OTHER_SUBJECT_ID);

            $anonymiser = new DbalAuditResourceAnonymiser($connection);
            $affected = $anonymiser->anonymise(AuditResource::of(self::PERSON_TYPE, self::SUBJECT_ID), $pseudonym);

            $this->assertSame(2, $affected, 'both rows naming the subject, and only those');

            foreach ([$selfActed, $adminActed] as $id) {
                $row = $this->rowById($connection, $id);
                $this->assertSame($pseudonym, $row['resource_id']);
                $this->assertTrue($this->isFlagSet($row['resource_erased']));
            }

            // The admin's own attribution survives intact — this is the asymmetry the two columns exist for.
            $admin = $this->rowById($connection, $adminActed);
            $this->assertSame(self::ADMIN_ID, $admin['actor_id']);
            $this->assertFalse($this->isFlagSet($admin['actor_erased']), 'the acting admin is not an erased actor');
            $this->assertSame(self::CLIENT_IP, $admin['ip'], "a third party's ip is not collateral");
            $this->assertSame(self::USER_AGENT, $admin['user_agent'], 'nor their user agent');

            foreach ([$bankRow, $otherRow] as $id) {
                $untouched = $this->rowById($connection, $id);
                $this->assertNotSame($pseudonym, $untouched['resource_id']);
                $this->assertFalse($this->isFlagSet($untouched['resource_erased']));
            }
        });
    }

    #[Test]
    public function itRedactsRequestMetadataOnlyWhereTheActorWasNeverIdentified(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $writer = new DbalAuditLogWriter($connection);
            $pseudonym = Uuid::generate();

            // The leaking shape: a self-service path (failed login, recovery throttle) names the subject
            // as its resource while nobody is authenticated, so the captured ip may be the subject's own.
            $anonymous = $this->seed($writer, ActorContext::anonymous(), self::PERSON_TYPE, self::SUBJECT_ID);
            $anonymiser = new DbalAuditResourceAnonymiser($connection);
            $affected = $anonymiser->anonymise(AuditResource::of(self::PERSON_TYPE, self::SUBJECT_ID), $pseudonym);

            // Every row naming the subject, anonymous or not — the `WHERE` carries no `actor_type`, and the
            // count is 1 here only because this test seeds one row.
            $this->assertSame(1, $affected, 'every row naming the subject');

            $redacted = $this->rowById($connection, $anonymous);
            $this->assertSame(self::SENTINEL, $redacted['ip'], 'the requester ip may be the subject');
            $this->assertSame(self::SENTINEL, $redacted['user_agent'], 'and so may the user agent');
            $this->assertSame($pseudonym, $redacted['resource_id']);
            $this->assertTrue($this->isFlagSet($redacted['resource_erased']));
            // Never identified is not identified-and-then-erased: raising the flag here would corrupt it.
            $this->assertFalse($this->isFlagSet($redacted['actor_erased']), 'no actor was ever identified');
        });
    }

    /**
     * The other direction, and a separate test because it fails for the opposite reason: over-reach onto a
     * value the request never carried. "Never captured" must not become "captured and then erased" — the
     * whole reason the sentinel is not NULL — and the guard has to hold **per column and per spelling of
     * empty**, since a client may send no `User-Agent` at all and a bare `User-Agent:` header seals `''`.
     * Seeding both columns alike is what lets one arm's guard be pasted into the other, or the two
     * cross-wired, with nothing red.
     */
    #[Test]
    public function itNeverReportsAValueThatWasNotCapturedAsRedacted(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $writer = new DbalAuditLogWriter($connection);
            $pseudonym = Uuid::generate();

            $bareRow = $this->seedAnonymous($writer, null, null);
            $halfRow = $this->seedAnonymous($writer, self::CLIENT_IP, null);
            $emptyAgentRow = $this->seedAnonymous($writer, self::CLIENT_IP, '');
            $emptyIpRow = $this->seedAnonymous($writer, '', self::USER_AGENT);

            $anonymiser = new DbalAuditResourceAnonymiser($connection);
            $anonymiser->anonymise(AuditResource::of(self::PERSON_TYPE, self::SUBJECT_ID), $pseudonym);

            $bare = $this->rowById($connection, $bareRow);
            $this->assertNull($bare['ip'], 'a value never captured is not rewritten into evidence');
            $this->assertNull($bare['user_agent']);
            $this->assertSame($pseudonym, $bare['resource_id'], 'yet the subject is still forgotten here');

            $half = $this->rowById($connection, $halfRow);
            $this->assertSame(self::SENTINEL, $half['ip'], 'the captured half is redacted');
            $this->assertNull($half['user_agent'], 'and the absent half is not invented — the guards are per column');

            $emptyAgent = $this->rowById($connection, $emptyAgentRow);
            $this->assertSame('', $emptyAgent['user_agent'], 'an empty header captured nothing to redact');
            $this->assertSame(self::SENTINEL, $emptyAgent['ip']);

            $emptyIp = $this->rowById($connection, $emptyIpRow);
            $this->assertSame('', $emptyIp['ip'], 'an empty ip captured nothing to redact either');
            $this->assertSame(self::SENTINEL, $emptyIp['user_agent']);
        });
    }

    #[Test]
    public function itSparesASystemActorWhoseActorIdIsAlsoNull(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $writer = new DbalAuditLogWriter($connection);
            $pseudonym = Uuid::generate();

            // A system actor CARRYING request metadata. Nothing in the entry factory forbids it, so this row
            // is what separates `actor_type = anonymous` from the `actor_id IS NULL` spelling: the latter
            // matches here too and would redact values this axis has no claim on. That system rows normally
            // carry none is emergent — two classes reading one RequestStack — and asserted by nothing.
            $system = $this->seed(
                $writer,
                ActorContext::system(),
                self::PERSON_TYPE,
                self::SUBJECT_ID,
                self::SYSTEM_IP,
            );
            // An anonymous actor on a resource this erasure does not name at all.
            $unrelated = $this->seed($writer, ActorContext::anonymous(), 'Bank', self::BANK_ID);

            $anonymiser = new DbalAuditResourceAnonymiser($connection);
            $anonymiser->anonymise(AuditResource::of(self::PERSON_TYPE, self::SUBJECT_ID), $pseudonym);

            $operator = $this->rowById($connection, $system);
            $this->assertSame(self::SYSTEM_IP, $operator['ip'], 'a system actor is not an anonymous one');
            $this->assertSame(self::USER_AGENT, $operator['user_agent'], 'and its user agent is no different');
            $this->assertFalse($this->isFlagSet($operator['actor_erased']));
            // The resource axis still does its own work on that row — only the metadata is spared.
            $this->assertSame($pseudonym, $operator['resource_id']);
            $this->assertTrue($this->isFlagSet($operator['resource_erased']));

            $untouched = $this->rowById($connection, $unrelated);
            $this->assertSame(self::CLIENT_IP, $untouched['ip'], 'another resource keeps everything');
            $this->assertSame(self::USER_AGENT, $untouched['user_agent']);
            $this->assertFalse($this->isFlagSet($untouched['resource_erased']));
        });
    }

    #[Test]
    public function itIsIdempotentBecauseTheOriginalIdNoLongerMatches(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $writer = new DbalAuditLogWriter($connection);
            $this->seed($writer, ActorContext::forUser(self::SUBJECT_ID), self::PERSON_TYPE, self::SUBJECT_ID);

            $anonymiser = new DbalAuditResourceAnonymiser($connection);
            $resource = AuditResource::of(self::PERSON_TYPE, self::SUBJECT_ID);

            $this->assertSame(1, $anonymiser->anonymise($resource, Uuid::generate()));
            $this->assertSame(0, $anonymiser->anonymise($resource, Uuid::generate()), 'a re-run matches nothing');
        });
    }

    #[Test]
    public function itRefusesAMalformedPseudonymBeforeReachingTheDriver(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $this->expectException(InvalidUuidException::class);

            (new DbalAuditResourceAnonymiser($connection))
                ->anonymise(AuditResource::of(self::PERSON_TYPE, self::SUBJECT_ID), 'not-a-uuid')
            ;
        });
    }

    /**
     * The leaking shape with its two request-metadata columns spelled explicitly, because which column
     * carries what is the whole subject of the test that uses this.
     */
    private function seedAnonymous(DbalAuditLogWriter $writer, ?string $ip, ?string $userAgent): string
    {
        return $this->seed(
            $writer,
            ActorContext::anonymous(),
            self::PERSON_TYPE,
            self::SUBJECT_ID,
            $ip,
            $userAgent,
        );
    }

    private function seed(
        DbalAuditLogWriter $writer,
        ActorContext $actor,
        string $resourceType,
        string $resourceId,
        ?string $ip = self::CLIENT_IP,
        ?string $userAgent = self::USER_AGENT,
    ): string {
        $entry = AuditLogEntry::create(
            'USER_ROLES_CHANGED',
            AuditLevel::SECURITY,
            $actor,
            Uuid::generate(),
            new DateTimeImmutable('2026-07-01T10:00:00+00:00'),
            AuditResource::of($resourceType, $resourceId),
            [],
            $ip,
            $userAgent,
        );
        $writer->write($entry);

        return $entry->id;
    }

    /**
     * @return array{
     *     actor_id: mixed,
     *     ip: mixed,
     *     user_agent: mixed,
     *     actor_erased: mixed,
     *     resource_id: mixed,
     *     resource_erased: mixed,
     * }
     */
    private function rowById(Connection $connection, string $id): array
    {
        $row = $connection->fetchAssociative(
            'SELECT actor_id, ip, user_agent, actor_erased, resource_id, resource_erased '
            . 'FROM audit_log WHERE id = :id',
            ['id' => $id],
        );
        $this->assertIsArray($row);
        /** @phpstan-var array{actor_id: mixed, ip: mixed, user_agent: mixed, actor_erased: mixed, resource_id: mixed, resource_erased: mixed} $row */

        return $row;
    }

    /**
     * The driver surfaces a Postgres boolean as a native bool or as `'t'`/`'f'` depending on the platform.
     */
    private function isFlagSet(mixed $value): bool
    {
        return true === $value || 't' === $value || '1' === $value || 1 === $value;
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
