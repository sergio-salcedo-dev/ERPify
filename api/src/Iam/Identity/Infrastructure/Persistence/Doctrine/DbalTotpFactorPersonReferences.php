<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Iam\Identity\Domain\Entity\TotpFactor;
use Erpify\Shared\Persistence\Infrastructure\KeysetDistinctIds;
use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Override;

/**
 * {@link PersonReferenceSource} over `identity_totp_factor.user_id` via plain DBAL — a `DISTINCT` read in bounded
 * keyset pages ({@see KeysetDistinctIds}), never a mutation and never a hydration.
 *
 * The preventive half proves the identity erasure DELETES this row; only this read proves it is GONE. A factor
 * row has no expiry and no sweep, so one that survived a partial erasure would sit here, its key live, until the
 * reconciler asked. The unique index on `user_id` is what each page seeks through. Ids only — never the sealed
 * secret or the scope beside them.
 */
final readonly class DbalTotpFactorPersonReferences implements PersonReferenceSource
{
    private KeysetDistinctIds $ids;

    public function __construct(Connection $connection, int $pageSize = KeysetDistinctIds::DEFAULT_PAGE_SIZE)
    {
        $this->ids = new KeysetDistinctIds($connection, $pageSize);
    }

    #[Override]
    public function axis(): PersonReferenceAxis
    {
        return PersonReferenceAxis::of(TotpFactor::class . '::$userId');
    }

    #[Override]
    public function retainedPersonIds(): array
    {
        return $this->ids->idsOf('identity_totp_factor', 'user_id');
    }
}
