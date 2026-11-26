<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Audit;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Audit\Infrastructure\Http\AuditDiffDisclosure;
use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Shared\Privacy\Application\PersonalDataClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ties the disclosure registry to the two things that decide it: the entity's own ORM mapping, which is the
 * universe of fields a diff can name, and its `#[PersonalData]` classification, which is the part that must never be
 * served in clear. The registry is strings because the audit module may not import the modules it records; this is
 * the test that imports both, so a column added to an audited aggregate — or a column that becomes personal —
 * reds here instead of being served by accident or withheld in silence.
 *
 * A green proves, for every ORM-mapped class implementing {@see AuditedEntity}: the registry has an entry for its
 * resource type, that entry lists exactly its mapped, non-personal fields, and it lists no personal one. It says
 * nothing about an audited aggregate that is not ORM-mapped, nor about whether the classification itself is right:
 * a field wrongly left unmarked is served in clear, and review is the only control on that direction.
 *
 * @internal
 */
#[CoversClass(AuditDiffDisclosure::class)]
final class AuditDiffDisclosureRegistryTest extends KernelTestCase
{
    public function testTheRegistryHoldsExactlyTheNonPersonalMappedFieldsOfEveryAuditedAggregate(): void
    {
        self::bootKernel();
        $classifier = self::getContainer()->get(PersonalDataClassifier::class);
        $this->assertInstanceOf(PersonalDataClassifier::class, $classifier);

        $audited = $this->auditedEntityClasses();
        $this->assertNotEmpty($audited, 'the walk found no audited aggregate, so it proves nothing');

        $types = [];

        foreach ($audited as $class) {
            $type = $this->resourceTypeOf($class);
            $types[] = $type;
            $this->assertArrayHasKey($type, AuditDiffDisclosure::CLEAR_FIELDS, $class . ' has no disclosure entry');

            $personal = $classifier->personalFieldsOf($class);
            $expected = \array_values(\array_diff($this->mappedFieldsOf($class), $personal));
            \sort($expected);
            $declared = AuditDiffDisclosure::CLEAR_FIELDS[$type];
            \sort($declared);

            $this->assertSame(
                $expected,
                $declared,
                $type . ': the clear fields differ from the mapping minus #[PersonalData]',
            );
            $this->assertSame(
                [],
                \array_values(\array_intersect($declared, $personal)),
                $type . ' lists a personal field',
            );
        }

        $declaredTypes = \array_keys(AuditDiffDisclosure::CLEAR_FIELDS);
        \sort($types);
        \sort($declaredTypes);
        $this->assertSame($types, $declaredTypes, 'the registry names a resource type no audited aggregate declares');
    }

    /**
     * @return list<class-string>
     */
    private function auditedEntityClasses(): array
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $manager);

        $classes = [];

        foreach ($manager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (\is_subclass_of($metadata->getName(), AuditedEntity::class)) {
                $classes[] = $metadata->getName();
            }
        }

        return $classes;
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private function mappedFieldsOf(string $class): array
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $manager);

        return \array_values($manager->getClassMetadata($class)->getFieldNames());
    }

    /**
     * The type is the literal the aggregate hands `AuditResource::of()`: it is a compliance contract independent of
     * the class name, and reading it off the method's source avoids constructing an aggregate just to ask.
     *
     * @param class-string $class
     */
    private function resourceTypeOf(string $class): string
    {
        $method = (new ReflectionClass($class))->getMethod('auditResource');
        $file = $method->getFileName();
        $this->assertIsString($file);

        $first = $method->getStartLine() - 1;
        $lines = \array_slice(\file($file) ?: [], $first, $method->getEndLine() - $first);
        $matched = \preg_match("/AuditResource::of\\('([A-Za-z0-9]+)'/", \implode('', $lines), $found);
        $this->assertSame(1, $matched, $class);

        return $found[1];
    }
}
