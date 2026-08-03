<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Override;

/**
 * {@link PersonReferenceSource} over `identity_password_reset_token.user_id` via plain DBAL — a `DISTINCT`
 * read, never a mutation and never a hydration.
 *
 * This context owns both the person and this table, so the reference never crosses a boundary — and it is in
 * the control all the same. The defect the control detects is a partial erasure, and "the use case that owns
 * it is one class away" is not a property anything enforces: the registry classifies this column exactly like
 * the three that do cross a boundary, so exempting it would carve a quarter of the obligation out of the
 * check while staying compile-clean.
 *
 * Nothing reaps this table either: `deleteExpired()` exists and nothing schedules it, so a lapsed row keeps
 * its `user_id` indefinitely — a listing read that filtered on expiry would miss exactly those rows. Ids
 * only, never the `token_hash` beside them.
 *
 * `DISTINCT` is load-bearing — a person can request a reset repeatedly, and although each request supersedes
 * its predecessor the guarantee owed here is "each id once", not "each row once". `ORDER BY` keeps the set
 * stable across runs so a diffing alert cannot fire on noise; `user_id` is indexed, so the scan stays
 * indexed as the table grows.
 */
final readonly class DbalPasswordResetTokenPersonReferences implements PersonReferenceSource
{
    public function __construct(private Connection $connection)
    {
    }

    #[Override]
    public function axis(): PersonReferenceAxis
    {
        return PersonReferenceAxis::of(PasswordResetToken::class . '::$userId');
    }

    #[Override]
    public function retainedPersonIds(): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT user_id FROM identity_password_reset_token ORDER BY user_id',
        );

        return \array_values(\array_filter($ids, \is_string(...)));
    }
}
