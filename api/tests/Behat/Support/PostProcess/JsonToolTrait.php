<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\PostProcess;

use BackedEnum;
use DateMalformedStringException;
use DateTime;
use Erpify\Tests\Behat\Support\Json\Json;
use Erpify\Tests\Behat\Support\Json\JsonInspector;
use Erpify\Tests\Behat\Support\Json\JsonSchema;
use Exception;
use JsonException;
use stdClass;
use Symfony\Component\PropertyAccess\Exception\NoSuchIndexException;
use Symfony\Component\PropertyAccess\Exception\NoSuchPropertyException;
use UnexpectedValueException;

/**
 * The node assertions every JSON-reading context shares.
 *
 * Every one of them reads its node through {@see readNode()} rather than through the inspector, so
 * three decisions are made once: the accessor never sees a `::<modifier>` suffix, an absent path is a
 * failed assertion rather than a raw accessor exception, and an unreadable path stays distinguishable
 * from an absent one.
 */
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

    /**
     * @throws JsonException
     */
    public function jsonShouldNotBeValid(Json $json, JsonSchema $jsonSchema): void
    {
        try {
            $this->getJsonInspector()->validate($json, $jsonSchema);
        } catch (UnexpectedValueException) {
            // The only failure that means "the schema rejected this document": every other way the
            // validation can end — an unresolvable $ref, an unreadable schema, a missing library —
            // never applied the schema at all, and accepting those as "not valid" makes the step pass
            // hardest exactly when it checked least.
            return;
        }

        self::fail('Given JSON validates the schema');
    }

    /**
     * @throws Exception
     */
    public function jsonPropertyShouldBeEqualTo(Json $json, string $property, mixed $expectedValue): void
    {
        $expectedValue = $this->propertyPostProcessValue($property, $expectedValue);

        if ($expectedValue instanceof BackedEnum) {
            $expectedValue = $expectedValue->value;
        }

        $value = $this->readNode($json, $property);
        $actual = $this->propertyPostProcessValue($property, $value);

        if (\is_float($actual)) {
            $actual = (string) $actual;
        }

        self::assertEquals(
            $expectedValue,
            $actual,
            \sprintf(
                'Property %s value is %s but %s was expected',
                $property,
                $this->describeNode($value),
                $this->describeNode($expectedValue),
            ),
        );
    }

    public function jsonPropertyShouldNotBeEqualTo(Json $json, string $property, mixed $expectedValue): void
    {
        $expectedValue = $this->propertyPostProcessValue($property, $expectedValue);
        $value = $this->readNode($json, $property);

        self::assertNotSame(
            $expectedValue,
            $value,
            \sprintf(
                'Property %s value is %s, which is equal to %s, but should not',
                $property,
                $this->describeNode($value),
                $this->describeNode($expectedValue),
            ),
        );
    }

    public function jsonPropertyShouldBeNull(Json $json, string $property): void
    {
        $value = $this->readNode($json, $property);
        self::assertNull(
            $value,
            \sprintf('Property %s value is %s but it should have been null', $property, $this->describeNode($value)),
        );
    }

    public function jsonPropertyShouldNotBeNull(Json $json, string $property): void
    {
        self::assertNotNull(
            $this->readNode($json, $property),
            \sprintf('Property %s value is null but it should not.', $property),
        );
    }

    public function jsonPropertyShouldBeFalse(Json $json, string $property): void
    {
        self::assertFalse(
            $this->booleanNode($json, $property),
            \sprintf('Property %s is true but it should have been false', $property),
        );
    }

    public function jsonPropertyShouldBeTrue(Json $json, string $property): void
    {
        self::assertTrue(
            $this->booleanNode($json, $property),
            \sprintf('Property %s is false but it should have been true', $property),
        );
    }

    public function jsonPropertyShouldExist(Json $json, string $property): void
    {
        try {
            $this->getJsonInspector()->evaluate($json, $this->nodePath($property));
        } catch (UnexpectedValueException $unexpectedValueException) {
            $this->rethrowUnlessAbsent($unexpectedValueException);

            self::fail(\sprintf('The property "%s" does not exist.', $property));
        }
    }

    public function jsonPropertyShouldNotExist(Json $json, string $property): void
    {
        try {
            $this->getJsonInspector()->evaluate($json, $this->nodePath($property));
        } catch (UnexpectedValueException $unexpectedValueException) {
            // Absence is what this step is here to observe. Anything else — a selector the accessor
            // cannot parse above all — is the step being broken, and swallowing it would report the
            // typo as the very absence the scenario set out to prove.
            $this->rethrowUnlessAbsent($unexpectedValueException);

            return;
        }

        self::fail(\sprintf('The property "%s" exists.', $property));
    }

    public function jsonPropertyShouldHaveElements(Json $json, string $property, int $count): void
    {
        $value = $this->readNode($json, $property);

        // Counting `(array) $value` counts the cast, not the node: it wraps a scalar into a
        // one-element array and turns null into an empty one, so "should have 1 element" holds for
        // any scalar and "should have 0 elements" holds for an explicit null. Both are assertions
        // about a collection passing against a value that is not one.
        if (!\is_array($value) && !$value instanceof stdClass) {
            self::fail(\sprintf(
                'Property %s holds %s, which is not a collection, so it has no elements to count '
                . '(%d expected)',
                $property,
                \get_debug_type($value),
                $count,
            ));
        }

        $currentCount = \count((array) $value);
        self::assertSame(
            $count,
            $currentCount,
            \sprintf('Property %s has %d children whereas it should have %d', $property, $currentCount, $count),
        );
    }

    public function jsonPropertyShouldBeTyped(Json $json, string $property, string $type): void
    {
        $value = $this->readNode($json, $property);
        self::assertSame(
            $type,
            \gettype($value),
            \sprintf('Property %s is typed %s whereas it should have been %s', $property, \gettype($value), $type),
        );
    }

    public function jsonPropertyShouldMatch(Json $json, string $property, string $pattern): void
    {
        $value = $this->readNode($json, $property);
        self::assertMatchesRegularExpression(
            $pattern,
            $this->stringNode($property, $value),
            \sprintf('The node value is %s', $this->describeNode($value)),
        );
    }

    public function jsonPropertyShouldContains(Json $json, string $property, string $text): void
    {
        $value = $this->readNode($json, $property);
        self::assertStringContainsString(
            $text,
            $this->stringNode($property, $value),
            \sprintf('The node value is %s, which does not contains "%s"', $this->describeNode($value), $text),
        );
    }

    public function jsonPropertyShouldNotContains(Json $json, string $property, string $text): void
    {
        $value = $this->readNode($json, $property);
        self::assertStringNotContainsString(
            $text,
            $this->stringNode($property, $value),
            \sprintf('The node value is %s, which contains "%s"', $this->describeNode($value), $text),
        );
    }

    /**
     * @throws DateMalformedStringException
     */
    public function jsonPropertyDateShouldBeEqualTo(Json $json, string $property, string $expected): void
    {
        $value = $this->readNode($json, $property);

        $expectedTime = (int) (new DateTime($expected))->format('U');
        $foundTime = (int) (new DateTime($this->stringNode($property, $value)))->format('U');
        self::assertLessThan(2, (int) (\abs($expectedTime - $foundTime) / 60));
    }

    public function jsonPropertyShouldBeOneOf(Json $json, string $property, string $list): void
    {
        $actual = $this->readNode($json, $property);

        $values = \explode(',', $list);
        $values = \array_map(trim(...), $values);

        self::assertTrue(
            \in_array($actual, $values, true),
            \sprintf('The node value is %s, which is not one of "%s"', $this->describeNode($actual), $list),
        );
    }

    /**
     * The path as the property accessor must see it: without the `::<modifier>` suffix, which names
     * how the value is compared rather than where it lives. Left on, it asks for a node no payload
     * carries and turns a working selector into an absent one.
     */
    private function nodePath(string $property): string
    {
        return $this->propertyPostProcessName($property);
    }

    /**
     * Reads the node an assertion is about, reporting a path the payload does not carry as a failed
     * assertion instead of an `UnexpectedValueException` naming the accessor's internals.
     *
     * It still throws, and that is load-bearing beyond the message. {@see \Erpify\Tests\Behat\Context\OutboxContext}
     * decides whether an event matches a table by running these assertions and catching what they
     * throw — the exception *is* the predicate — so a missing property that returned null here would
     * make an outbox table step match an event that never carried it, manufacturing exactly the
     * vacuous assertion this trait exists to deny. Pinned by
     * {@see \Erpify\Tests\Unit\Behat\Context\OutboxTableMatchTest}.
     */
    private function readNode(Json $json, string $property): mixed
    {
        try {
            return $this->getJsonInspector()->evaluate($json, $this->nodePath($property));
        } catch (UnexpectedValueException $unexpectedValueException) {
            $this->rethrowUnlessAbsent($unexpectedValueException);

            self::fail(\sprintf('Property "%s" does not exist in the JSON.', $property));
        }
    }

    /**
     * An absent node is an unmet expectation and reads as a failure; a selector the accessor cannot
     * parse is a broken step and keeps its own exception. Calling both "does not exist" would send
     * the next reader hunting a payload field for what is a typo in the step.
     *
     * @throws UnexpectedValueException
     */
    private function rethrowUnlessAbsent(UnexpectedValueException $exception): void
    {
        $cause = $exception->getPrevious();

        if ($cause instanceof NoSuchIndexException || $cause instanceof NoSuchPropertyException) {
            return;
        }

        throw $exception;
    }

    /**
     * `filter_var(…, FILTER_VALIDATE_BOOLEAN)` maps everything it does not recognise to false, so
     * filtering the raw node would let "should be false" hold for "", 0, null, [] and any
     * unrecognised string — every absent-ish value in a payload satisfying an assertion about a
     * boolean. JSON has a boolean type and `json_decode` produces it, so demanding one costs nothing
     * a real payload has.
     */
    private function booleanNode(Json $json, string $property): bool
    {
        $value = $this->readNode($json, $property);

        if (!\is_bool($value)) {
            self::fail(\sprintf(
                'Property %s holds %s, which is not a boolean',
                $property,
                \get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * `(string) $value` on an array raises a warning and yields "Array", which then satisfies a
     * "contains" or a pattern that happens to match that word rather than the payload.
     */
    private function stringNode(string $property, mixed $value): string
    {
        if (!\is_scalar($value)) {
            self::fail(\sprintf(
                'Property %s holds %s, which has no string form to compare',
                $property,
                \get_debug_type($value),
            ));
        }

        return (string) $value;
    }

    /**
     * Failure messages are built eagerly, on every call, so they may never interpolate a value
     * directly: `%s` on an array raises a warning and prints "Array" whether the assertion failed or
     * not.
     */
    private function describeNode(mixed $value): string
    {
        if (\is_scalar($value) || null === $value) {
            return \var_export($value, true);
        }

        return \sprintf('%s(%s)', \get_debug_type($value), \json_encode($value) ?: '?');
    }
}
