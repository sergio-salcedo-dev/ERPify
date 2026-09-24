<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Shared\Persistence\Infrastructure\KeysetDistinctIds;
use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Override;

/**
 * {@link PersonReferenceSource} over `identity_recovery_secret.user_id` via plain DBAL — a `DISTINCT` read in
 * bounded keyset pages ({@see KeysetDistinctIds}), never a mutation and never a hydration.
 *
 * This context owns both the person and this table, so the reference never crosses a boundary, and it is in
 * the control all the same: the defect the control detects is a PARTIAL erasure, and "the use case that owns
 * it is one class away" is not a property anything enforces.
 *
 * **It is the axis with the least chance of self-correcting, which is the reason to want it most.** The
 * sibling reset-token table at least has a `deleteExpired()` and rows that die within the hour; this one has
 * a ten-year TTL, no sweep of any kind, and one row per identity — so a `user_id` that survived its erasure
 * here would simply sit there until the reconciler asked. Reporting it is the only thing that will.
 *
 * The unique index on `user_id` (`uniq_identity_recovery_secret_user_id`) makes `DISTINCT` collapse nothing
 * today; it stays because the contract owes "each id once", and a source should not hand that promise to a
 * constraint its own SQL never states. That same index is what each page seeks through, so the read stays a range scan
 * however many identities the installation holds. `ORDER BY` keeps the set stable across runs so a diffing
 * alert cannot fire on ordering noise. Ids only — never the digest beside them, which is a credential.
 */
final readonly class DbalRecoverySecretPersonReferences implements PersonReferenceSource
{
    private KeysetDistinctIds $ids;

    public function __construct(Connection $connection, int $pageSize = KeysetDistinctIds::DEFAULT_PAGE_SIZE)
    {
        $this->ids = new KeysetDistinctIds($connection, $pageSize);
    }

    #[Override]
    public function axis(): PersonReferenceAxis
    {
        return PersonReferenceAxis::of(RecoverySecret::class . '::$userId');
    }

    #[Override]
    public function retainedPersonIds(): array
    {
        return $this->ids->idsOf('identity_recovery_secret', 'user_id');
    }
}
