<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Override;

/**
 * {@link PersonReferenceSource} over `identity_recovery_secret.user_id` via plain DBAL — a read, never a
 * mutation and never a hydration.
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
 * No `DISTINCT`, and the asymmetry with the reset-token source is deliberate rather than an oversight: a
 * unique index on `user_id` already makes "each id once" a property of the table, so de-duplicating would be
 * asking the database to prove something its own constraint guarantees. `ORDER BY` stays, so the set is
 * stable across runs and a diffing alert cannot fire on ordering noise. Ids only — never the digest beside
 * them, which is a credential.
 */
final readonly class DbalRecoverySecretPersonReferences implements PersonReferenceSource
{
    public function __construct(private Connection $connection)
    {
    }

    #[Override]
    public function axis(): PersonReferenceAxis
    {
        return PersonReferenceAxis::of(RecoverySecret::class . '::$userId');
    }

    #[Override]
    public function retainedPersonIds(): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT user_id FROM identity_recovery_secret ORDER BY user_id',
        );

        return \array_values(\array_filter($ids, \is_string(...)));
    }
}
