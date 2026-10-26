<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Shared\Kernel\Domain\ValueObject\NormalizedText;
use Erpify\Shared\Privacy\Domain\PersonalData;
use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * A mapped `<field>Normalized` column is a second copy of `<field>` ({@see NormalizedText}), so it carries the
 * same `#[PersonalData]` classification as the field it folds — in both directions.
 *
 * The direction that hurts is an unclassified twin of a personal field: the change capture diffs every mapped
 * column and `PiiDiffSealer` seals only the classified ones, so the folded name would be written to
 * `audit_log` in clear, beside the ciphertext of the name it was folded from, in the one table no erasure
 * rewrites. Nothing else fails: the search works, every scenario passes, and the leak is a value that looks
 * like a lower-cased copy of something already sealed. The converse (a classified twin of a non-personal
 * field) is a misclassification rather than a leak, and is refused because the pair is one fact.
 *
 * The universe is every concrete `#[ORM\Entity]` under `src`, so a twin added to a future aggregate — a
 * customer's name, a subcontractor's trade name — joins it the moment it is mapped.
 *
 * What a green does not prove: that a folded copy stored under any other name is classified, nor that the
 * twin is written with the rule the search side reads it with.
 *
 * @internal
 */
#[CoversNothing]
final class NormalizedTwinPersonalDataGateTest extends TestCase
{
    private const string SUFFIX = 'Normalized';

    #[Test]
    public function everyNormalizedTwinIsClassifiedLikeTheFieldItFolds(): void
    {
        $pairs = $this->normalizedTwins();

        foreach ([Bank::class . '::$nameNormalized', BankAccount::class . '::$holderNameNormalized'] as $known) {
            $this->assertArrayHasKey($known, $pairs, \sprintf(
                'The entity walk did not find %s, which exists: the sweep would pass over a partial universe.',
                $known,
            ));
        }

        foreach ($pairs as $twin => [$class, $field]) {
            $this->assertTrue($class->hasProperty($field), \sprintf(
                '%s folds a field named "%s", which %s does not declare. Name the twin after the field it '
                . 'folds, so its classification can be compared with that field\'s.',
                $twin,
                $field,
                $class->getName(),
            ));

            $fieldIsPersonal = $this->isPersonal($class->getProperty($field));
            $twinIsPersonal = $this->isPersonal($class->getProperty($field . self::SUFFIX));

            $this->assertSame($fieldIsPersonal, $twinIsPersonal, \sprintf(
                '%s and the field it folds disagree on #[PersonalData] (field: %s, twin: %s). A twin is a copy '
                . 'of the same value: an unclassified copy of personal data reaches audit_log unsealed.',
                $twin,
                $fieldIsPersonal ? 'personal' : 'not personal',
                $twinIsPersonal ? 'personal' : 'not personal',
            ));
        }
    }

    /**
     * @return array<string, array{ReflectionClass<object>, string}> `<Fqcn>::$<twin>` => [class, folded field]
     */
    private function normalizedTwins(): array
    {
        $root = ApiSourceFiles::root();
        $pairs = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $source = \file_get_contents($file->getPathname());

            if (false === $source || !\str_contains($source, 'ORM\Entity')) {
                continue;
            }

            $relative = \substr($file->getPathname(), \strlen($root) + 1, -\strlen('.php'));
            $fqcn = 'Erpify\\' . \str_replace('/', '\\', $relative);

            if (!\class_exists($fqcn)) {
                continue;
            }

            $class = new ReflectionClass($fqcn);

            if ($class->isAbstract() || [] === $class->getAttributes(Entity::class)) {
                continue;
            }

            foreach ($class->getProperties() as $property) {
                $name = $property->getName();

                if (
                    !\str_ends_with($name, self::SUFFIX)
                    || self::SUFFIX === $name
                    || [] === $property->getAttributes(Column::class)
                ) {
                    continue;
                }

                $pairs[$fqcn . '::$' . $name] = [$class, \substr($name, 0, -\strlen(self::SUFFIX))];
            }
        }

        \ksort($pairs);

        return $pairs;
    }

    private function isPersonal(ReflectionProperty $property): bool
    {
        return [] !== $property->getAttributes(PersonalData::class);
    }
}
