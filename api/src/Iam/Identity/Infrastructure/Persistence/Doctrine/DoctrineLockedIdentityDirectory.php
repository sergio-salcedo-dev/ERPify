<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Iam\Identity\Application\CorruptIdentityRow;
use Erpify\Iam\Identity\Domain\Repository\LockedIdentityDirectory;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link LockedIdentityDirectory} over `identity_user` via plain DBAL — one statement returning a single
 * column, never a hydration and never a mutation.
 *
 * One predicate, and deliberately only one. The notification policy is not restated here: see the port for
 * why a second copy of it would drift in the direction nothing can observe.
 *
 * It is a sequential scan and a sort: neither `locked_until` nor `lockout_notified_at` is indexed, and none
 * is added. One organization per installation puts the table in the tens-to-hundreds of rows and this runs
 * once per tick, so an index would be cost with no measurement behind it. Said plainly here because the
 * sibling directories' "indexed" phrasing is true of a primary-key probe and would not travel to this query.
 *
 * The instant is bound as `DATETIME_IMMUTABLE` rather than pre-formatted, so the comparison happens in the
 * column's own type instead of resting on two string representations happening to sort alike.
 */
#[AsAlias(LockedIdentityDirectory::class)]
final readonly class DoctrineLockedIdentityDirectory implements LockedIdentityDirectory
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @throws CorruptIdentityRow when a row holds something an identity id cannot be — the sweep's output is
     *                            an absence, so a dropped candidate would be a notice nobody knows was skipped
     *
     * @return list<string>
     */
    #[Override]
    public function findLockedAt(DateTimeImmutable $now): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM identity_user WHERE locked_until > :now ORDER BY id',
            ['now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        return \array_map($this->asIdentityId(...), $ids);
    }

    private function asIdentityId(mixed $id): string
    {
        if (!\is_string($id)) {
            throw CorruptIdentityRow::idIsNotAString('identity_user.id');
        }

        return $id;
    }
}
