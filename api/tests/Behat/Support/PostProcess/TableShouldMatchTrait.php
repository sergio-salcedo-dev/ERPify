<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\PostProcess;

use Behat\Gherkin\Node\TableNode;
use Erpify\Tests\Behat\NodeModifier\NodeModifierInterface;
use Symfony\Component\PropertyAccess\Exception\ExceptionInterface as PropertyAccessExceptionInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

trait TableShouldMatchTrait
{
    use ArrayToolTrait;
    use PropertyPostProcessTrait;

    /**
     * Compare an entity (or any object/array) against a Gherkin data table.
     *
     * Path syntax:
     *   - "field" — strict equals
     *   - "field?" — equals, but missing path is allowed (treated as null)
     *   - "field::<modifier>" — apply a registered NodeModifier (e.g. ::date, ::amount)
     */
    public function valueShouldMatch(mixed $entity, TableNode $table): void
    {
        foreach ($table->getRowsHash() as $path => $expected) {
            if (\is_array($expected)) {
                $expected = \implode(',', \array_map(static fn (string $value): string => $value, $expected));
            }

            $this->treatRow($path, $expected, $entity);
        }
    }

    /**
     * @SuppressWarnings("PHPMD.NPathComplexity")
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    private function treatRow(string $path, ?string $expected, mixed $entity): void
    {
        $allowMissingPath = false;

        if (\str_ends_with($path, '?')) {
            $path = \substr($path, 0, -1);
            $allowMissingPath = true;
        }

        if (null !== $expected && 'null' === \trim($expected)) {
            $expected = null;
        }

        $nodeModifier = $this->nodeModifierLocator->getFor($path, $expected);

        if (!$nodeModifier instanceof NodeModifierInterface) {
            $actual = $this->getValueAtPath($entity, $path, $allowMissingPath);
            $this->validateGenericValue($path, $expected, $actual);

            return;
        }

        $actual = $this->getValueAtPath($entity, $nodeModifier->getPathCleaned($path), $allowMissingPath);

        self::assertTrue(
            $nodeModifier->compare($expected, $actual),
            \sprintf(
                'Value for property %s is %s, expected %s',
                $path,
                \json_encode($actual),
                \json_encode($expected),
            ),
        );
    }

    private function getValueAtPath(mixed $entity, string $path, bool $allowMissingPath): mixed
    {
        if (!\is_object($entity) && !\is_array($entity)) {
            return null;
        }

        try {
            return PropertyAccess::createPropertyAccessorBuilder()
                ->enableExceptionOnInvalidIndex()
                ->getPropertyAccessor()
                ->getValue($entity, $path)
            ;
        } catch (PropertyAccessExceptionInterface $propertyAccessException) {
            if (!$allowMissingPath) {
                throw $propertyAccessException;
            }
        }

        return null;
    }

    private function validateGenericValue(string $property, mixed $expected, mixed $actual): void
    {
        self::assertEquals(
            $this->postProcessExpected($expected, $actual),
            $actual,
            \sprintf(
                'Value for property %s is %s, expected %s',
                $property,
                \json_encode($actual),
                \json_encode($expected),
            ),
        );
    }

    private function postProcessExpected(mixed $expected, mixed $actual): mixed
    {
        if (\is_array($actual) && null === $expected) {
            return [];
        }

        if (!\is_string($expected)) {
            return $expected;
        }

        if (\is_int($actual)) {
            return (int) $expected;
        }

        if (\is_bool($actual)) {
            return \filter_var($expected, FILTER_VALIDATE_BOOLEAN);
        }

        if (\is_string($actual) && \str_starts_with($expected, '"') && \str_ends_with($expected, '"')) {
            return \trim($expected, '"');
        }

        return $expected;
    }
}
