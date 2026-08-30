<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Image persistence by COMPOSITION: implements its domain port with an injected
 * {@see EntityManagerInterface}, never by inheriting an ORM base class.
 *
 * The flush is what makes the refused write catchable HERE. `DoctrineTransactionManager` translates
 * only retryable and referential failures, so a uniqueness violation left to surface at commit would
 * cross into the application layer as a raw driver exception — carrying, in its message, the value of
 * the key that collided. Translating it at this border is the same contract
 * {@see \Erpify\Backoffice\Bank\Infrastructure\Persistence\Doctrine\DoctrineBankRepository} keeps, and
 * {@see ConcurrentUniqueWrite} deliberately drops the driver's text rather than chaining it.
 *
 * A flush inside an open transaction synchronises without committing, so the surrounding
 * `TransactionManager::transactional()` remains the only thing that decides when the work becomes
 * durable.
 */
#[AsAlias(ImageRepository::class)]
final readonly class DoctrineImageRepository implements ImageRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Override]
    public function save(Image $image): void
    {
        try {
            $this->entityManager->persist($image);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw ConcurrentUniqueWrite::onWrite('image');
        }
    }

    #[Override]
    public function remove(Image $image): void
    {
        $this->entityManager->remove($image);
        $this->entityManager->flush();
    }

    #[Override]
    public function findById(ImageId $id): ?Image
    {
        return $this->entityManager->find(Image::class, $id->toString());
    }
}
