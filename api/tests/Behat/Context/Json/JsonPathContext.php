<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context\Json;

use Behat\Step\Then;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\NodeModifier\NodeModifierInterface;
use Erpify\Tests\Behat\Support\Json\JsonResponseAwareTrait;
use Erpify\Tests\Behat\Support\PostProcess\JsonPathToolTrait;
use Erpify\Tests\Behat\Support\PostProcess\PropertyPostProcessTrait;
use Flow\JSONPath\JSONPathException;
use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

/**
 * Validates sets of JSON nodes matched by a JSONPath/JMESPath selector against the last HTTP response.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
class JsonPathContext extends AbstractContext
{
    use JsonPathToolTrait;
    use JsonResponseAwareTrait;
    use PropertyPostProcessTrait;

    /**
     * Main function for jsonpath steps.
     *
     * @throws JsonException
     */
    #[Then('the JSON nodes matching :nodeSelector should :operator value :expected')]
    public function theJsonNodesMatchingShould(string $nodeSelector, string $operator, int|string $expected): void
    {
        match ($operator) {
            'be equal to' => $this->theMatchingJsonNodeShouldBeEqualTo($nodeSelector, $expected),
            'be greater than' => $this->theMatchingJsonNodeShouldBe($nodeSelector, 'greater', $expected),
            'be greater than or equal to' => $this->theMatchingJsonNodeShouldBe(
                $nodeSelector,
                'greater or equal',
                $expected,
            ),
            'be less than' => $this->theMatchingJsonNodeShouldBe($nodeSelector, 'less', $expected),
            'be less than or equal to' => $this->theMatchingJsonNodeShouldBe($nodeSelector, 'less or equal', $expected),
            'be between' => $this->theMatchingJsonNodeValueShouldBeBetween($nodeSelector, (string) $expected),
            'be in' => $this->theMatchingJsonNodeValueShouldBeIn($nodeSelector, (string) $expected),
            'contain' => $this->theMatchingJsonNodeShouldContain($nodeSelector, $expected),
            'not be equal to' => $this->theMatchingJsonNodeShouldNotBeEqualTo($nodeSelector, $expected),
            'not be in' => $this->theMatchingJsonNodeValueShouldNotBeIn($nodeSelector, (string) $expected),
            'not contain' => $this->theMatchingJsonNodeShouldNotContain($nodeSelector, $expected),
            default => throw new UnexpectedValueException('Unknown operator'),
        };
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` are not equal to `expected`.
     *
     * @throws JSONPathException
     * @throws JsonException
     */
    public function theMatchingJsonNodeShouldNotBeEqualTo(string $nodeSelector, mixed $expected): void
    {
        $nodeModifier = $this->nodeModifierLocator->getFor($nodeSelector, $expected);

        if ($nodeModifier instanceof NodeModifierInterface) {
            $expected = $nodeModifier->getProcessedValue($expected);
            $nodeSelector = $nodeModifier->getPathCleaned($nodeSelector);
        }

        $values = $this->getValues($nodeSelector);

        foreach ($values as $index => $value) {
            self::assertNotEquals(
                $value,
                $expected,
                \sprintf(
                    'The node at index %d has value %s which is equal to %s',
                    $index,
                    \json_encode($value, JSON_THROW_ON_ERROR),
                    \json_encode($expected, JSON_THROW_ON_ERROR),
                ),
            );
        }
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` are not included in `expectedJson` array.
     */
    public function theMatchingJsonNodeValueShouldNotBeIn(string $nodeSelector, string $expectedJson): void
    {
        $values = $this->getValues($nodeSelector);
        $expected = \json_decode($expectedJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($expected);

        foreach ($values as $index => $value) {
            self::assertNotContains(
                $value,
                $expected,
                \sprintf(
                    'The node at index %d has value %s which is part of %s',
                    $index,
                    \json_encode($value, JSON_THROW_ON_ERROR),
                    \json_encode($expected, JSON_THROW_ON_ERROR),
                ),
            );
        }
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` does not contains a specific value.
     */
    public function theMatchingJsonNodeShouldNotContain(string $nodeSelector, int|string|null $expected): void
    {
        $nodeModifier = $this->nodeModifierLocator->getFor($nodeSelector, $expected);

        if ($nodeModifier instanceof NodeModifierInterface) {
            $expected = $nodeModifier->getProcessedValue($expected);
            $nodeSelector = $nodeModifier->getPathCleaned($nodeSelector);
        }

        $values = $this->getValues($nodeSelector);

        foreach ($values as $index => $value) {
            if (!\is_array($value)) {
                self::assertNotContains(
                    $expected,
                    $values,
                    \sprintf(
                        'The node at index %d has value %s which contains %s',
                        $index,
                        \json_encode($value, JSON_THROW_ON_ERROR),
                        \json_encode($expected, JSON_THROW_ON_ERROR),
                    ),
                );

                return;
            }

            self::assertNotContains(
                $expected,
                $value,
                \sprintf(
                    'The node at index %d has value %s which contains %s',
                    $index,
                    \json_encode($value, JSON_THROW_ON_ERROR),
                    \json_encode($expected, JSON_THROW_ON_ERROR),
                ),
            );
        }
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` are equal to `expected`.
     */
    public function theMatchingJsonNodeShouldBeEqualTo(string $nodeSelector, mixed $expected): void
    {
        $nodeModifier = $this->nodeModifierLocator->getFor($nodeSelector, $expected);

        if ($nodeModifier instanceof NodeModifierInterface) {
            $expected = $nodeModifier->getProcessedValue($expected);
            $nodeSelector = $nodeModifier->getPathCleaned($nodeSelector);
        }

        $values = $this->getValues($nodeSelector);

        foreach ($values as $index => $value) {
            self::assertEquals(
                $value,
                $expected,
                \sprintf(
                    'The node at index %d has value %s which is different from %s',
                    $index,
                    \json_encode($value),
                    \json_encode($expected),
                ),
            );
        }
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` are validated with `operator` and `expected` value.
     */
    public function theMatchingJsonNodeShouldBe(string $nodeSelector, string $operator, int|string $expected): void
    {
        $nodeModifier = $this->nodeModifierLocator->getFor($nodeSelector, $expected);

        if ($nodeModifier instanceof NodeModifierInterface) {
            $expected = $nodeModifier->getProcessedValue($expected);
            $nodeSelector = $nodeModifier->getPathCleaned($nodeSelector);
        }

        $values = $this->getValues($nodeSelector);

        foreach ($values as $index => $value) {
            $errorMessage = \sprintf(
                'The node at index %d has value %s, which is not %s than %s',
                $index,
                $this->scalarToString($value),
                $operator,
                $this->scalarToString($expected),
            );
            match ($operator) {
                'greater' => self::assertGreaterThan(
                    $expected,
                    $value,
                    $errorMessage,
                ),
                'greater or equal' => self::assertGreaterThanOrEqual(
                    $expected,
                    $value,
                    $errorMessage,
                ),
                'less' => self::assertLessThan(
                    $expected,
                    $value,
                    $errorMessage,
                ),
                'less or equal' => self::assertLessThanOrEqual(
                    $expected,
                    $value,
                    $errorMessage,
                ),
                default => self::fail('Unknown operator'),
            };
        }
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` are contained between `min` and `max`.
     */
    public function theMatchingJsonNodeValueShouldBeBetween(
        string $nodeSelector,
        string $expectedJson,
    ): void {
        $expected = \json_decode($expectedJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($expected);
        self::assertArrayHasKey(0, $expected);
        self::assertArrayHasKey(1, $expected);
        $expectedFrom = $expected[0];
        $expectedTo = $expected[1];
        $nodeModifier = $this->nodeModifierLocator->getFor($nodeSelector, $expectedFrom);

        if (!$nodeModifier instanceof NodeModifierInterface) {
            throw new UnexpectedValueException('NodeModifier not found');
        }

        $from = $nodeModifier->getProcessedValue($expectedFrom);
        $to = $nodeModifier->getProcessedValue($expectedTo);
        $nodeSelector = $nodeModifier->getPathCleaned($nodeSelector);
        $values = $this->getValues($nodeSelector);

        foreach ($values as $index => $value) {
            $value = $nodeModifier->getProcessedValue($value);
            $message = \sprintf(
                'The node at index %d has value %s, which is not between %s and %s',
                $index,
                $this->scalarToString($value),
                $this->scalarToString($expectedFrom),
                $this->scalarToString($expectedTo),
            );
            self::assertGreaterThanOrEqual($from, $value, $message);
            self::assertLessThanOrEqual($to, $value, $message);
        }
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` are included in `expectedJson` array.
     */
    public function theMatchingJsonNodeValueShouldBeIn(string $nodeSelector, string $expectedJson): void
    {
        $values = $this->getValues($nodeSelector);
        $expected = \json_decode($expectedJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($expected);

        foreach ($values as $index => $value) {
            self::assertContains(
                $value,
                $expected,
                \sprintf(
                    'The node at index %d has value %s which is not part of %s',
                    $index,
                    \json_encode($value, JSON_THROW_ON_ERROR),
                    \json_encode($expected, JSON_THROW_ON_ERROR),
                ),
            );
        }
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` have `expected` children.
     */
    #[Then('the JSON nodes matching :nodeSelector should have :expected children')]
    public function theMatchingJsonNodeShouldHaveChildrenCount(string $nodeSelector, int $expected): void
    {
        $values = $this->getValues($nodeSelector);

        foreach ($values as $index => $value) {
            self::assertIsArray($value);
            self::assertCount(
                $expected,
                $value,
                \sprintf(
                    'The node at index %d has %d children, but should have %d',
                    $index,
                    \count($value),
                    $expected,
                ),
            );
        }
    }

    /**
     * Checks that JSON node matching JMESPath is present a certain amount of time in the json with given value.
     */
    #[Then('the JSON nodes matching :nodeSelector with value :value should be found at least :expectedCount time')]
    public function theMatchingJsonNodeValueShouldFoundAtLeast(
        string $nodeSelector,
        string $expectedCount,
        string $value,
    ): void {
        $values = $this->getValues($nodeSelector);

        $count = 0;

        foreach ($values as $expectedValue) {
            if ($expectedValue === $value) {
                ++$count;
            }
        }

        self::assertGreaterThanOrEqual(
            $expectedCount,
            $count,
            \sprintf(
                'There is only %d count for value "%s" in node "%s" in the JSON (expect at least: %d)',
                $count,
                $value,
                $nodeSelector,
                $expectedCount,
            ),
        );
    }

    /**
     * Checks that JSON node matching JMESPath is present a certain amount of time in the json with given value.
     */
    #[Then('the JSON nodes matching :nodeSelector with value :value should be found exactly :expectedCount time')]
    public function theMatchingJsonNodeValueShouldFoundExactly(
        string $nodeSelector,
        int $expectedCount,
        string $value,
    ): void {
        $nodeModifier = $this->nodeModifierLocator->getFor($nodeSelector, $value);

        if ($nodeModifier instanceof NodeModifierInterface) {
            $value = $nodeModifier->getProcessedValue($value);
            $nodeSelector = $nodeModifier->getPathCleaned($nodeSelector);
        }

        $values = $this->getValues($nodeSelector);

        $count = 0;

        foreach ($values as $expectedValue) {
            if ($expectedValue === $value) {
                ++$count;
            }
        }

        self::assertEquals(
            $expectedCount,
            $count,
            \sprintf(
                'There is only %d count for value "%s" in node "%s" in the JSON (expect exactly: %d)',
                $count,
                $this->scalarToString($value),
                $nodeSelector,
                $expectedCount,
            ),
        );
    }

    /**
     * Checks that JSON node should exist in each object.
     */
    #[Then('the JSON nodes matching :nodeSelector should exist')]
    public function theMatchingJsonNodeShouldExist(string $nodeSelector): void
    {
        $originalNodeSelector = $nodeSelector;
        $values = \count($this->getValues($nodeSelector));

        // Check if the string contains a dot
        if (\str_contains($nodeSelector, '.')) {
            $length = \strrpos($nodeSelector, '.');

            if (false === $length) {
                throw new InvalidArgumentException('The nodeSelector is invalid');
            }

            // Remove the last segment after the last dot
            $nodeSelector = \substr($nodeSelector, 0, $length);
        }

        $fullvalues = \count($this->getValues($nodeSelector));

        self::assertEquals(
            $values,
            $fullvalues,
            \sprintf(
                'There is only %d count for node "%s" in the JSON (expect exactly: %d)',
                $values,
                $originalNodeSelector,
                $fullvalues,
            ),
        );
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` contains a specific value.
     */
    public function theMatchingJsonNodeShouldContain(string $nodeSelector, int|string|null $expected): void
    {
        $nodeModifier = $this->nodeModifierLocator->getFor($nodeSelector, $expected);

        if ($nodeModifier instanceof NodeModifierInterface) {
            $expected = $nodeModifier->getProcessedValue($expected);
            $nodeSelector = $nodeModifier->getPathCleaned($nodeSelector);
        }

        $values = $this->getValues($nodeSelector);

        foreach ($values as $index => $value) {
            if (!\is_array($value)) {
                self::assertContains(
                    $expected,
                    $values,
                    \sprintf(
                        'The node at index %d has value %s which does not contain %s',
                        $index,
                        \json_encode($value, JSON_THROW_ON_ERROR),
                        \json_encode($expected, JSON_THROW_ON_ERROR),
                    ),
                );

                return;
            }

            self::assertContains(
                $expected,
                $value,
                \sprintf(
                    'The node at index %d has value %s which does not contain %s',
                    $index,
                    \json_encode($value, JSON_THROW_ON_ERROR),
                    \json_encode($expected, JSON_THROW_ON_ERROR),
                ),
            );
        }
    }

    /** @throws JsonException */
    private function scalarToString(mixed $value): string
    {
        if (\is_scalar($value) || null === $value) {
            return (string) $value;
        }

        return \json_encode($value, JSON_THROW_ON_ERROR) ?: '';
    }
}
