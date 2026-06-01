<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Domain\Entity\Identifiable;
use Erpify\Shared\Infrastructure\Persistence\Entity\StoredDomainEvent;
use Erpify\Shared\Media\Domain\Entity\Media;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Root-cause regression lock for the aggregate id mismatch: every entity using the shared
 * `Identifiable` trait must keep an app-assigned id (Doctrine generator type NONE). If anyone
 * re-introduces `#[ORM\GeneratedValue]` / `CustomIdGenerator`, Doctrine would again overwrite the
 * app id at flush and the create domain event would diverge from the persisted PK.
 *
 * The entity set is discovered from Doctrine metadata rather than hard-coded, so any future
 * `Identifiable` user is covered automatically; the known users are asserted present so a
 * zero-discovery (e.g. metadata not loaded) cannot pass silently.
 *
 * @internal
 */
#[CoversNothing]
final class IdentifiableAssignedIdentifierTest extends KernelTestCase
{
    public function testEveryIdentifiableEntityUsesAnAppAssignedId(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $identifiableEntities = [];

        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $classMetadatum) {
            $entityClass = $classMetadatum->getName();

            if (!$this->usesIdentifiableTrait($entityClass)) {
                continue;
            }

            $identifiableEntities[] = $entityClass;

            $this->assertSame(
                ClassMetadata::GENERATOR_TYPE_NONE,
                $classMetadatum->generatorType,
                \sprintf('%s must use an app-assigned id (no Doctrine GeneratedValue).', $entityClass),
            );
        }

        // Guard against a silent zero-discovery: the known Identifiable users must all be present.
        $this->assertContains(Bank::class, $identifiableEntities);
        $this->assertContains(Media::class, $identifiableEntities);
        $this->assertContains(StoredDomainEvent::class, $identifiableEntities);
    }

    /**
     * Walks parent classes too: `Bank`/`Media` inherit the trait via
     * {@see \Erpify\Shared\Domain\Aggregate\AggregateRoot}, while `StoredDomainEvent` uses it directly.
     *
     * @param class-string $entityClass
     */
    private function usesIdentifiableTrait(string $entityClass): bool
    {
        $traits = [];

        for ($class = $entityClass; false !== $class; $class = \get_parent_class($class)) {
            $traits += \class_uses($class) ?: [];
        }

        return isset($traits[Identifiable::class]);
    }
}
