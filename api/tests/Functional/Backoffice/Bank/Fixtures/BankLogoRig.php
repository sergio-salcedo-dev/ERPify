<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Application\BankDeleter;
use Erpify\Backoffice\Bank\Application\BankFinder;
use Erpify\Backoffice\Bank\Application\BankLogoHolder;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Images\Application\ImageAttachment;
use Erpify\Shared\Images\Application\UploadImage;
use Erpify\Shared\Images\Infrastructure\InterventionImageProcessor;
use Erpify\Shared\Images\Infrastructure\Persistence\Doctrine\DoctrineImageRepository;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\Shared\Images\Fixtures\ImageReleaseRig;
use Erpify\Tests\Unit\Shared\Images\Infrastructure\LocalImageStorages;
use Psr\Log\NullLogger;

/**
 * What the bank logo functional cases share: the real services, a SECOND connection that sees only what committed, a
 * storage root of their own, the queue the release requests land in, and the removal of every row and object a case
 * made. Every case commits, so `tearDownRig()` is the only cleanup.
 *
 * @internal
 */
trait BankLogoRig
{
    private EntityManagerInterface $entityManager;

    private Connection $outside;

    private string $root;

    /** @var list<string> */
    private array $imageIdsBefore = [];

    /** @var list<string> */
    private array $bankIds = [];

    private function bootRig(): void
    {
        self::bootKernel();
        $this->entityManager = $this->service(EntityManagerInterface::class);
        // A second connection: only COMMITTED rows are visible from it.
        $this->outside = DriverManager::getConnection($this->entityManager->getConnection()->getParams());
        $this->root = \sys_get_temp_dir() . '/erpify-bank-logo-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root, 0o755, true);

        ImageReleaseRig::purge($this->outside);
        $this->imageIdsBefore = $this->imageIds();
    }

    private function tearDownRig(): void
    {
        if (isset($this->entityManager)) {
            $connection = $this->entityManager->getConnection();

            for ($level = $connection->getTransactionNestingLevel(); $level > 0; --$level) {
                $connection->rollBack();
            }
        }

        if (isset($this->outside)) {
            ImageReleaseRig::purge($this->outside);

            foreach ($this->bankIds as $bankId) {
                $this->outside->executeStatement('DELETE FROM audit_log WHERE resource_id = ?', [$bankId]);
                $this->outside->executeStatement('DELETE FROM bank WHERE id = ?', [$bankId]);
            }

            foreach (\array_diff($this->imageIds(), $this->imageIdsBefore) as $created) {
                $this->outside->executeStatement('DELETE FROM image WHERE id = ?', [$created]);
            }

            $this->outside->close();
        }

        if (isset($this->root)) {
            \exec('rm -rf ' . \escapeshellarg($this->root));
        }
    }

    private function seedBank(?string $logoImageId): string
    {
        $id = Uuid::generate();
        $token = \strtoupper(\substr(\str_replace('-', '', $id), 0, 12));
        $this->outside->executeStatement(
            'INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at, logo_image_id) '
            . 'VALUES (?, ?, ?, ?, NOW(), NOW(), ?)',
            [$id, 'Logo Bank ' . $token, 'logo bank ' . \strtolower($token), 'LG' . $token, $logoImageId],
        );
        $this->bankIds[] = $id;

        return $id;
    }

    private function holder(): BankLogoHolder
    {
        return new BankLogoHolder($this->service(BankRepository::class), $this->service(BankFinder::class));
    }

    private function deleter(?BankRepository $repository = null): BankDeleter
    {
        $repository ??= $this->service(BankRepository::class);
        $transactions = $this->transactions();

        return new BankDeleter(
            $repository,
            new BankFinder($repository),
            $this->service(BankAccountRepository::class),
            (new ImageReleaseRig($this->entityManager))->eventBus(),
            $transactions,
            $this->attachment($transactions),
        );
    }

    private function attachment(?TransactionManager $transactions = null): ImageAttachment
    {
        $transactions ??= $this->transactions();

        return new ImageAttachment(
            new UploadImage(
                $this->processor(),
                LocalImageStorages::rootedAt($this->root),
                new DoctrineImageRepository($this->entityManager),
                $transactions,
            ),
            $transactions,
            (new ImageReleaseRig($this->entityManager))->eventBus(),
            new NullLogger(),
        );
    }

    private function transactions(): TransactionManager
    {
        return $this->service(TransactionManager::class);
    }

    private function processor(): InterventionImageProcessor
    {
        $container = self::getContainer();
        $integer = static fn (string $name): int => (int) $container->getParameter($name);

        return new InterventionImageProcessor(
            $integer('erpify.images.max_input_bytes'),
            $integer('erpify.images.max_decoded_pixels'),
            $integer('erpify.images.max_input_dimension'),
            $integer('erpify.images.max_output_dimension'),
            $integer('erpify.images.encoding_quality'),
            new NullLogger(),
        );
    }

    private function png(): string
    {
        return (string) \file_get_contents(\dirname(__DIR__, 4) . '/Fixtures/Images/valid.png');
    }

    private function heldBy(string $bankId): ?string
    {
        $held = $this->outside->fetchOne('SELECT logo_image_id FROM bank WHERE id = ?', [$bankId]);

        return \is_string($held) ? $held : null;
    }

    private function bankRows(string $bankId): int
    {
        $count = $this->outside->fetchOne('SELECT COUNT(*) FROM bank WHERE id = ?', [$bankId]);

        return \is_numeric($count) ? (int) $count : -1;
    }

    private function queuedFor(string $imageId): int
    {
        return ImageReleaseRig::queuedFor($this->outside, $imageId);
    }

    private function queuedTotal(): int
    {
        $count = $this->outside->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?',
            [ImageReleaseRig::QUEUE],
        );

        return \is_numeric($count) ? (int) $count : -1;
    }

    /**
     * @return list<string> the images this case stored
     */
    private function mintedImageIds(): array
    {
        return \array_values(\array_diff($this->imageIds(), $this->imageIdsBefore));
    }

    /**
     * @return list<string>
     */
    private function imageIds(): array
    {
        $ids = [];

        foreach ($this->outside->fetchFirstColumn('SELECT id FROM image') as $id) {
            if (\is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
