<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\TotpFactor;
use Erpify\Iam\Identity\Domain\Repository\TotpFactorRepository;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
use Override;

/**
 * In-memory {@see TotpFactorRepository} that records what a case saves and removes. The locking finder journals
 * its acquisition, standing in for the `FOR UPDATE` the production adapter takes, so the erasure's lock order can
 * be asserted over this table too.
 *
 * @internal
 */
final class InMemoryTotpFactorRepository implements TotpFactorRepository
{
    /** @var list<TotpFactor> */
    public array $saved = [];

    /** @var list<TotpFactor> */
    public array $removed = [];

    /** Set when a test is asserting WHERE this table's lock falls among the others. */
    public ?LockOrderJournal $lockOrderJournal = null;

    /** @var array<string, TotpFactor> keyed by the lower-cased user id */
    private array $byUser = [];

    public function __construct(TotpFactor ...$preset)
    {
        foreach ($preset as $factor) {
            $this->byUser[\strtolower($factor->userId())] = $factor;
        }
    }

    #[Override]
    public function save(TotpFactor $factor): void
    {
        $this->saved[] = $factor;
        $this->byUser[\strtolower($factor->userId())] = $factor;
    }

    #[Override]
    public function remove(TotpFactor $factor): void
    {
        $this->removed[] = $factor;
        unset($this->byUser[\strtolower($factor->userId())]);
    }

    #[Override]
    public function findByUserIdForUpdate(string $userId): ?TotpFactor
    {
        $this->lockOrderJournal?->locked(LockOrderJournal::TOTP_FACTOR);

        // Case-insensitive like the Postgres `uuid` column the real adapter matches on.
        return $this->byUser[\strtolower($userId)] ?? null;
    }
}
