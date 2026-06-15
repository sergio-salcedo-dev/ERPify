<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support\Tool;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Tests\Behat\Support\Tool\EntityClassResolver;
use Erpify\Tests\Unit\Behat\Support\Tool\Fixtures\Backoffice\CollidingEntity as BackofficeCollidingEntity;
use Erpify\Tests\Unit\Behat\Support\Tool\Fixtures\Frontoffice\CollidingEntity as FrontofficeCollidingEntity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(EntityClassResolver::class)]
final class EntityClassResolverTest extends TestCase
{
    #[Test]
    public function itReturnsAFullyQualifiedNameUnchanged(): void
    {
        $resolver = new EntityClassResolver($this->createStub(EntityManagerInterface::class));

        $this->assertSame(Bank::class, $resolver->resolve(Bank::class, null));
    }

    #[Test]
    public function itResolvesAUniqueShortNameToItsMappedFqcn(): void
    {
        $resolver = $this->resolverForMappedEntities(Bank::class);

        $this->assertSame(Bank::class, $resolver->resolve('Bank', null));
    }

    #[Test]
    public function itHonoursTheConfiguredNamespaceBeforeBasenameResolution(): void
    {
        $resolver = new EntityClassResolver($this->createStub(EntityManagerInterface::class));

        $this->assertSame(Bank::class, $resolver->resolve('Bank', 'Erpify\Backoffice\Bank\Domain\Entity'));
    }

    #[Test]
    public function itThrowsWhenNoMappedEntityMatchesTheShortName(): void
    {
        $resolver = $this->resolverForMappedEntities(Bank::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No mapped entity matches the short name "Nope".');

        $resolver->resolve('Nope', null);
    }

    #[Test]
    public function itThrowsListingEveryCandidateWhenAShortNameIsAmbiguous(): void
    {
        $resolver = $this->resolverForMappedEntities(
            BackofficeCollidingEntity::class,
            FrontofficeCollidingEntity::class,
        );

        try {
            $resolver->resolve('CollidingEntity', null);
            $this->fail('Expected ambiguous short-name resolution to throw.');
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->assertStringContainsString(
                BackofficeCollidingEntity::class,
                $invalidArgumentException->getMessage(),
            );
            $this->assertStringContainsString(
                FrontofficeCollidingEntity::class,
                $invalidArgumentException->getMessage(),
            );
        }
    }

    #[Test]
    public function itResolvesEachCollidingFqcnToItselfDespiteTheBasenameClash(): void
    {
        $resolver = $this->resolverForMappedEntities(
            BackofficeCollidingEntity::class,
            FrontofficeCollidingEntity::class,
        );

        $this->assertSame(
            BackofficeCollidingEntity::class,
            $resolver->resolve(BackofficeCollidingEntity::class, null),
        );
        $this->assertSame(
            FrontofficeCollidingEntity::class,
            $resolver->resolve(FrontofficeCollidingEntity::class, null),
        );
    }

    /**
     * @param class-string ...$mappedClasses
     */
    private function resolverForMappedEntities(string ...$mappedClasses): EntityClassResolver
    {
        $allMetadata = [];

        foreach ($mappedClasses as $mappedClass) {
            $metadata = $this->createStub(ClassMetadata::class);
            $metadata->method('getName')->willReturn($mappedClass);
            $allMetadata[] = $metadata;
        }

        $metadataFactory = $this->createStub(ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn($allMetadata);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getMetadataFactory')->willReturn($metadataFactory);

        return new EntityClassResolver($entityManager);
    }
}
