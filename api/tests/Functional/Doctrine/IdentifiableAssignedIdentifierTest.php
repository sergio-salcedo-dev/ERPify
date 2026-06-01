<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Infrastructure\Persistence\Entity\StoredDomainEvent;
use Erpify\Shared\Media\Domain\Entity\Media;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Root-cause regression lock for the aggregate id mismatch: every entity using the shared
 * `Identifiable` trait must keep an app-assigned id (Doctrine generator type NONE). If anyone
 * re-introduces `#[ORM\GeneratedValue]` / `CustomIdGenerator`, Doctrine would again overwrite the
 * app id at flush and the create domain event would diverge from the persisted PK.
 *
 * @internal
 */
#[CoversNothing]
final class IdentifiableAssignedIdentifierTest extends KernelTestCase
{
    #[DataProvider('provideIdIsAppAssignedNotDoctrineGeneratedCases')]
    public function testIdIsAppAssignedNotDoctrineGenerated(string $entityClass): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $metadata = $entityManager->getClassMetadata($entityClass);

        $this->assertSame(ClassMetadata::GENERATOR_TYPE_NONE, $metadata->generatorType, \sprintf('%s must use an app-assigned id (no Doctrine GeneratedValue).', $entityClass));
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function provideIdIsAppAssignedNotDoctrineGeneratedCases(): iterable
    {
        yield 'Bank' => [Bank::class];
        yield 'Media' => [Media::class];
        yield 'StoredDomainEvent' => [StoredDomainEvent::class];
    }
}
