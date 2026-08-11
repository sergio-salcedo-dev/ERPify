<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\CorruptIdentityRow;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DoctrineLockedIdentityDirectory;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Drives the candidate query against REAL Postgres, which is the only layer where its three load-bearing
 * details can go red: the instant bound as `DATETIME_IMMUTABLE` rather than as a pre-formatted string, the
 * strict `>` that makes an expiry exclusive, and the `ORDER BY id` the sweep's determinism rests on. Every
 * one of them is invisible to a unit test, whose directory double deliberately reimplements none of it.
 *
 * Ids are generated per run and asserted by containment, so the shared dev database's own rows — which may
 * well include locked identities of their own — can neither make this pass nor fail it. Each test runs inside
 * a rolled-back transaction and leaves nothing behind.
 *
 * @internal
 */
#[CoversClass(DoctrineLockedIdentityDirectory::class)]
final class DoctrineLockedIdentityDirectoryTest extends KernelTestCase
{
    private const string PROBE = '2026-08-11T12:00:00+00:00';

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private DoctrineLockedIdentityDirectory $directory;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();

        $this->directory = new DoctrineLockedIdentityDirectory($this->connection);
    }

    public function testItReturnsOnlyTheIdentitiesStillUnderALock(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $probe = new DateTimeImmutable(self::PROBE);

            $stillLocked = $this->seedLockedUntil($probe->add(new DateInterval('PT5M')));
            $lapsed = $this->seedLockedUntil($probe->sub(new DateInterval('PT5M')));
            $neverLocked = $this->seedIdentity();

            $found = $this->directory->findLockedAt($probe);

            $this->assertContains($stillLocked, $found);
            $this->assertNotContains($lapsed, $found, 'A lapsed lock is not a lock.');
            $this->assertNotContains($neverLocked, $found, 'A null expiry must not be selected.');
        });
    }

    /**
     * The expiry is exclusive, and the column is `timestamp(0)`, so the smallest observable step is one
     * second. Both directions are driven from the same seeded row: `>=` would put the first assertion red and
     * leave the second green, which is precisely the distinction a single-sided test cannot make.
     */
    public function testTheExpiryBoundaryIsExclusive(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $expiry = new DateTimeImmutable(self::PROBE);
            $id = $this->seedLockedUntil($expiry);

            $this->assertNotContains(
                $id,
                $this->directory->findLockedAt($expiry),
                'At its own expiry the lock is already over.',
            );
            $this->assertContains(
                $id,
                $this->directory->findLockedAt($expiry->sub(new DateInterval('PT1S'))),
                'One second earlier it still holds.',
            );
        });
    }

    /**
     * The sweep's work has to be deterministic across ticks, which is what lets a per-row fault cost one
     * notice instead of starving whatever the arbitrary order happened to put behind it.
     *
     * **Seeded in descending id order, and that is the whole test.** UUIDv7 is time-ordered, so ids minted in
     * sequence are already ascending and a scan returning them in physical order looks perfectly sorted —
     * measured: with the `ORDER BY` deleted this test still passed, 3/3. Inserting them backwards is what
     * makes physical order and id order disagree, so the clause is the only thing that can produce the
     * assertion below.
     */
    public function testItOrdersTheIdentitiesById(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $probe = new DateTimeImmutable(self::PROBE);
            $expiry = $probe->add(new DateInterval('PT5M'));

            $ids = [Uuid::generate(), Uuid::generate(), Uuid::generate()];
            \rsort($ids, SORT_STRING);

            foreach ($ids as $id) {
                $this->seedLockedUntil($expiry, $id);
            }

            $mine = \array_values(\array_filter(
                $this->directory->findLockedAt($probe),
                static fn (string $id): bool => \in_array($id, $ids, true),
            ));
            $this->assertCount(3, $mine, 'all three seeded identities came back');

            $ascending = $ids;
            \sort($ascending, SORT_STRING);
            $this->assertSame($ascending, $mine);
        });
    }

    /**
     * A row that is not an identity id stops the sweep instead of shrinking it. Driven through a mocked
     * connection because the schema cannot produce one — `identity_user.id` is a non-null `uuid` — which is
     * exactly why the guard needs a test of its own: nothing else in the tree goes red if it is deleted, and
     * what it would silently become is a locked owner omitted from the sweep with no record of the omission.
     */
    public function testACorruptRowStopsTheSweepRatherThanShrinkingIt(): void
    {
        // A stub, not a mock: the call is the test's input, not an expectation about it — the assertion is
        // the throw. Configuring it as a mock with no expectation is what PHPUnit notices.
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([42]);

        $this->expectException(CorruptIdentityRow::class);

        (new DoctrineLockedIdentityDirectory($connection))->findLockedAt(new DateTimeImmutable(self::PROBE));
    }

    /**
     * Seeds an identity locked until exactly `$expiry`, driving the aggregate rather than writing the column
     * by hand so the ORM mapping is exercised on the way in. The seed is read back and asserted before any
     * test leans on it: an `INSERT` that silently wrote nothing would otherwise make every assertion here
     * pass over an empty set.
     */
    private function seedLockedUntil(DateTimeImmutable $expiry, ?string $id = null): string
    {
        $attemptsAt = $expiry->sub(new DateInterval(User::LOCK_DURATION));
        $id ??= Uuid::generate();
        $user = $this->newIdentity($id);

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $user->recordFailedAttempt($attemptsAt);
        }

        $this->assertTrue($user->isLockedAt($attemptsAt), 'the aggregate really locked before it was written');
        $user->pullDomainEvents();

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->assertSame(
            $expiry->format('Y-m-d H:i:s'),
            $this->lockedUntilOf($id),
            'the expiry reached the column intact',
        );

        return $id;
    }

    private function seedIdentity(): string
    {
        $id = Uuid::generate();
        $this->entityManager->persist($this->newIdentity($id));
        $this->entityManager->flush();

        $this->assertNull($this->lockedUntilOf($id), 'an unlocked identity really has a null expiry');

        return $id;
    }

    private function newIdentity(string $id): User
    {
        return User::register(
            $id,
            \sprintf('locked-%s@erpify.test', $id),
            HashedPassword::fromHash('hashed-' . $id),
            Role::AUDIT_READER,
        );
    }

    private function lockedUntilOf(string $id): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT locked_until FROM identity_user WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );

        $this->assertNotFalse($value, 'the seeded row exists');

        if (null === $value) {
            return null;
        }

        $this->assertIsString($value);

        return $value;
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
