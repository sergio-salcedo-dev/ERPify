<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Images\Fixtures;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Images\Application\ImageHolder;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Tests\Unit\Shared\Images\Application\Double\OwnerNotFound;
use Override;

/**
 * An owner of one image over a real, COMMITTED table (`image_attachment_probe`), created by the case and dropped by
 * its teardown. It reads and writes through the application's own connection so that it joins the transaction the
 * attach service opens, and takes the owner row `FOR UPDATE` the way a consumer's repository does.
 *
 * @internal
 */
final class ProbeHolder implements ImageHolder
{
    public const string TABLE = 'image_attachment_probe';

    /** Runs once the lock is held, inside the attach transaction. @var (Closure(): void)|null */
    public ?Closure $afterLock = null;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public static function create(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (owner_id UUID PRIMARY KEY, image_id UUID NULL)',
        );
    }

    public static function drop(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    public static function seed(Connection $connection, string $ownerId, ?string $imageId): void
    {
        $connection->executeStatement(
            'INSERT INTO ' . self::TABLE . ' (owner_id, image_id) VALUES (?, ?)',
            [$ownerId, $imageId],
        );
    }

    /**
     * The id the owner holds, as `$connection` sees it — a second connection sees only what was committed.
     */
    public static function heldAsSeenFrom(Connection $connection, string $ownerId): ?string
    {
        $held = $connection->fetchOne('SELECT image_id FROM ' . self::TABLE . ' WHERE owner_id = ?', [$ownerId]);

        return \is_string($held) ? $held : null;
    }

    #[Override]
    public function lockAndRead(string $ownerId): ?ImageId
    {
        $held = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT image_id FROM ' . self::TABLE . ' WHERE owner_id = ? FOR UPDATE',
            [$ownerId],
        );

        if (false === $held) {
            throw new OwnerNotFound();
        }

        if ($this->afterLock instanceof Closure) {
            ($this->afterLock)();
        }

        return \is_string($held['image_id'] ?? null) ? ImageId::fromString($held['image_id']) : null;
    }

    #[Override]
    public function attach(string $ownerId, ?ImageId $imageId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE ' . self::TABLE . ' SET image_id = ? WHERE owner_id = ?',
            [$imageId?->toString(), $ownerId],
        );
    }
}
