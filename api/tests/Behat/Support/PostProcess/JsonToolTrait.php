<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\PostProcess;

use BackedEnum;
use DateMalformedStringException;
use DateTime;
use Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumInterface;
use Erpify\Tests\Behat\Assertion\Json\Json;
use Erpify\Tests\Behat\Assertion\Json\JsonInspector;
use Erpify\Tests\Behat\Assertion\Json\JsonSchema;
use Exception;
use JsonException;
use PHPUnit\Framework\AssertionFailedError;

trait JsonToolTrait
{
    use PropertyPostProcessTrait;

    private ?JsonInspector $jsonInspector = null;

    public function getJsonInspector(): JsonInspector
    {
        if (null === $this->jsonInspector) {
            $this->jsonInspector = new JsonInspector('javascript');
        }

        return $this->jsonInspector;
    }

    /**
     * @throws JsonException
     */
    public function jsonShouldBeValid(Json $json, JsonSchema $jsonSchema): void
    {
        self::assertTrue(
            $this->getJsonInspector()->validate($json, $jsonSchema),
            'Given JSON does not validate the schema',
        );
    }

    public function jsonShouldNotBeValid(Json $json, JsonSchema $jsonSchema): void
    {
        try {
            $this->getJsonInspector()->validate($json, $jsonSchema);
            self::fail('Given JSON validates the schema');
        } catch (Exception $exception) {
            if ($exception instanceof AssertionFailedError) {
                throw $exception;
            }

            return;
        }
    }

    /**
     * @throws Exception
     */
    public function jsonPropertyShouldBeEqualTo(Json $json, string $property, mixed $expectedValue): void
    {
        $expectedValue = $this->propertyPostProcessValue($property, $expectedValue);

        if ($expectedValue instanceof HumanReadableIntEnumInterface) {
            $expectedValue = $expectedValue->getLabel();
        }

        if ($expectedValue instanceof BackedEnum) {
            $expectedValue = $expectedValue->value;
        }

        $propertyCleaned = $this->propertyPostProcessName($property);

        $value = $this->getJsonInspector()->evaluate($json, $propertyCleaned);
        $actual = $this->propertyPostProcessValue($property, $value);

        if (\is_float($actual)) {
            $actual = (string) $actual;
        }

        self::assertEquals(
            $expectedValue,
            $actual,
            \sprintf('Property %s value is "%s" but "%s" was expected', $property, $value, $expectedValue),
        );
    }

    public function jsonPropertyShouldNotBeEqualTo(Json $json, string $property, mixed $expectedValue): void
    {
        $expectedValue = $this->propertyPostProcessValue($property, $expectedValue);
        $property = $this->propertyPostProcessName($property);

        $value = $this->getJsonInspector()->evaluate($json, $property);

        self::assertNotSame(
            $expectedValue,
            $value,
            \sprintf(
                'Property %s value is "%s" which is equal to "%s", but should not',
                $property,
                $value,
                $expectedValue,
            ),
        );
    }

    public function jsonPropertyShouldBeNull(Json $json, string $property): void
    {
        $value = $this->getJsonInspector()->evaluate($json, $property);
        self::assertNull(
            $value,
            \sprintf('Property %s value is "%s" but it should have been null', $property, $value),
        );
    }

    public function jsonPropertyShouldNotBeNull(Json $json, string $property): void
    {
        $value = $this->getJsonInspector()->evaluate($json, $property);
        self::assertNotNull(
            $value,
            \sprintf('Property %s value is null but it should not.', $property),
        );
    }

    public function jsonPropertyShouldBeFalse(Json $json, string $property): void
    {
        $value = \filter_var($this->getJsonInspector()->evaluate($json, $property), FILTER_VALIDATE_BOOLEAN);
        self::assertFalse(
            $value,
            \sprintf('Property %s value is "%s" but it should have been false', $property, $value),
        );
    }

    public function jsonPropertyShouldBeTrue(Json $json, string $property): void
    {
        $value = \filter_var($this->getJsonInspector()->evaluate($json, $property), FILTER_VALIDATE_BOOLEAN);
        self::assertTrue(
            $value,
            \sprintf('Property %s value is "%s" but it should have been true', $property, $value),
        );
    }

    public function jsonPropertyShouldExist(Json $json, string $property): void
    {
        try {
            $this->getJsonInspector()->evaluate($json, $property);
        } catch (Exception) {
            self::fail(\sprintf('The property "%s" does not exist.', $property));
        }
    }

    public function jsonPropertyShouldNotExist(Json $json, string $property): void
    {
        try {
            $this->getJsonInspector()->evaluate($json, $property);
            self::fail(\sprintf('The property "%s" exists.', $property));
        } catch (Exception $exception) {
            if ($exception instanceof AssertionFailedError) {
                throw $exception;
            }
        }
    }

    public function jsonPropertyShouldHaveElements(Json $json, string $property, int $count): void
    {
        $value = $this->getJsonInspector()->evaluate($json, $property);
        $currentCount = \count((array) $value);
        self::assertEquals(
            $count,
            $currentCount,
            \sprintf('Property %s has %d children whereas it should have %d', $property, $currentCount, $count),
        );
    }

    public function jsonPropertyShouldBeTyped(Json $json, string $property, string $type): void
    {
        $value = $this->getJsonInspector()->evaluate($json, $property);
        self::assertTrue(
            \gettype($value) === $type,
            \sprintf('Property %s is typed %s whereas it should have been %s', $property, \gettype($value), $type),
        );
    }

    public function jsonPropertyShouldMatch(Json $json, string $property, string $pattern): void
    {
        $value = $this->getJsonInspector()->evaluate($json, $property);
        self::assertMatchesRegularExpression(
            $pattern,
            (string) $value,
            \sprintf("The node value is '%s'", \json_encode($value)),
        );
    }

    public function jsonPropertyShouldContains(Json $json, string $property, string $text): void
    {
        $value = $this->getJsonInspector()->evaluate($json, $property);
        self::assertStringContainsString(
            $text,
            (string) $value,
            \sprintf("The node value is '%s', which does not contains '%s'", \json_encode($value), $text),
        );
    }

    public function jsonPropertyShouldNotContains(Json $json, string $property, string $text): void
    {
        $value = $this->getJsonInspector()->evaluate($json, $property);
        self::assertStringNotContainsString(
            $text,
            (string) $value,
            \sprintf("The node value is '%s', which contains '%s'", \json_encode($value), $text),
        );
    }

    /**
     * @throws DateMalformedStringException
     */
    public function jsonPropertyDateShouldBeEqualTo(Json $json, string $property, string $expected): void
    {
        $value = $this->getJsonInspector()->evaluate($json, $property);

        $expectedTime = (int) (new DateTime($expected))->format('U');
        $foundTime = (int) (new DateTime($value))->format('U');
        self::assertLessThan(2, (int) (\abs($expectedTime - $foundTime) / 60));
    }

    public function jsonPropertyShouldBeOneOf(Json $json, string $property, string $list): void
    {
        $actual = $this->getJsonInspector()->evaluate($json, $property);

        $values = \explode(',', $list);
        $values = \array_map(trim(...), $values);

        self::assertTrue(\in_array($actual, $values, true), \sprintf('The node value is "%s", which is not one of "%s"', $actual, $list));
    }
}
