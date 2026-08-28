<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Images;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Erpify\Shared\Images\Infrastructure\Persistence\Doctrine\DoctrineImageRepository;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The lifecycle port against real Postgres.
 *
 * Every case runs inside a transaction that is always rolled back — the suite has no DAMA
 * auto-rollback and shares the dev database connection.
 *
 * @internal
 */
#[CoversClass(DoctrineImageRepository::class)]
final class DoctrineImageRepositoryTest extends KernelTestCase
{
    public function testItSavesAnImageAndFindsItBackByItsIdentity(): void
    {
        $this->withRepository(function (ImageRepository $repository, EntityManagerInterface $entityManager): void {
            $imageId = ImageId::generate();
            $repository->save(new Image($imageId, \str_repeat('c', 64), 'image/png', 32, 32, 512));

            $entityManager->clear();

            $found = $repository->findById($imageId);

            $this->assertInstanceOf(Image::class, $found);
            $this->assertTrue($imageId->equals($found->id()));
        });
    }

    /**
     * `null` means the row is confirmed absent, and only that. A database failure is an exception, so a
     * caller can never read "I could not look" as "it is not there" — which on the deletion path would
     * turn a broken connection into a confirmed erasure.
     */
    public function testFindingAnAbsentIdentityAnswersNullRatherThanFailing(): void
    {
        $this->withRepository(function (ImageRepository $repository): void {
            $this->assertNotInstanceOf(Image::class, $repository->findById(ImageId::generate()));
        });
    }

    public function testRemovingAnImageLeavesNoRowBehind(): void
    {
        $this->withRepository(function (ImageRepository $repository, EntityManagerInterface $entityManager): void {
            $imageId = ImageId::generate();
            $repository->save(new Image($imageId, \str_repeat('d', 64), 'image/webp', 16, 16, 256));

            $entityManager->clear();
            $persisted = $repository->findById($imageId);
            $this->assertInstanceOf(Image::class, $persisted, 'assert the row exists before asserting it is gone');

            $repository->remove($persisted);
            $entityManager->clear();

            $this->assertNotInstanceOf(Image::class, $repository->findById($imageId));
        });
    }

    /**
     * Identity is unique by construction, and the primary key is what makes a collision fail rather than
     * silently overwrite. It must fail TRANSLATED: the driver's own message names the offending key value,
     * and that text reaches `messenger_messages` through `ErrorDetailsStamp` and Sentry, neither of which
     * any erasure path can reach.
     */
    public function testASecondImageUnderTheSameIdentityIsRefusedAndTranslated(): void
    {
        $this->withRepository(function (ImageRepository $repository, EntityManagerInterface $entityManager): void {
            $imageId = ImageId::generate();
            $repository->save(new Image($imageId, \str_repeat('e', 64), 'image/png', 8, 8, 64));

            $entityManager->clear();

            $this->expectException(ConcurrentUniqueWrite::class);
            $repository->save(new Image($imageId, \str_repeat('f', 64), 'image/jpeg', 9, 9, 81));
        });
    }

    /**
     * @param callable(ImageRepository, EntityManagerInterface): void $work
     */
    private function withRepository(callable $work): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $repository = new DoctrineImageRepository($entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $work($repository, $entityManager);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
