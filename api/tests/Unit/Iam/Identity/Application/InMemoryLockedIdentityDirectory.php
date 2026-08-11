<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Repository\LockedIdentityDirectory;
use Override;

/**
 * In-memory {@see LockedIdentityDirectory} that hands back every seeded id, **ignoring `$now` entirely**.
 *
 * The ignoring is the design. Re-implementing `locked_until > :now` here would make the sweep's unit tests
 * assert against a second copy of the SQL rather than against the aggregate: a candidate the real query
 * would have dropped would be dropped by the fake too, so the tests would pass whether or not
 * {@see \Erpify\Iam\Identity\Domain\Entity\User::awaitsLockoutNoticeAt()} filtered anything at all. Handing
 * over everything makes that predicate the only thing standing between a seeded identity and a mail, which
 * is exactly the claim under test. The real predicate is falsified one layer down, against the database, in
 * {@see \Erpify\Tests\Functional\Iam\Identity\DoctrineLockedIdentityDirectoryTest}.
 *
 * @internal
 */
final class InMemoryLockedIdentityDirectory implements LockedIdentityDirectory
{
    /**
     * Instants the sweep queried with, so a test can prove the clock was read once and passed through.
     *
     * @var list<DateTimeImmutable>
     */
    public array $queriedAt = [];

    /**
     * @param list<string> $ids
     */
    public function __construct(private readonly array $ids = [])
    {
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function findLockedAt(DateTimeImmutable $now): array
    {
        $this->queriedAt[] = $now;

        return $this->ids;
    }
}
