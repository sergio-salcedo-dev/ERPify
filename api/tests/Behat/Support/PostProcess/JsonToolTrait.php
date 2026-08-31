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
use Symfony\Component\PropertyAccess\Exception\UnexpectedTypeException;
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

    /**
     * How far a date node may sit from the expected instant. The steps that use it compare a value the
     * application stamped against a scenario's `"now"`, so the window has to cover the round trip and
     * nothing more; the previous arithmetic divided by 60 before comparing against 2, which admitted
     * anything under two minutes and reported no message when it did not.
     */
    private const int DATE_TOLERANCE_SECONDS = 5;

    private ?JsonInspector $jsonInspector = null;

    public function getJsonInspector(): JsonInspector
    {
        $this->jsonInspector ??= new JsonInspector('javascript');

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
        $actual = $this->comparableNode($this->propertyPostProcessValue($property, $value));
        $expectedValue = $this->comparableNode($expectedValue);

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

    /**
     * Both operands go through the modifier, and the comparison is loose, mirroring
     * {@see jsonPropertyShouldBeEqualTo()} exactly. Comparing the raw node with `===` against a value
     * Gherkin always delivers as a string made the step pass on the type rather than the value: a
     * node holding `5` satisfied `should not be equal to "5"`, and went on satisfying it if the API
     * started returning precisely the value the scenario forbids.
     */
    public function jsonPropertyShouldNotBeEqualTo(Json $json, string $property, mixed $expectedValue): void
    {
        $expectedValue = $this->propertyPostProcessValue($property, $expectedValue);

        if ($expectedValue instanceof BackedEnum) {
            $expectedValue = $expectedValue->value;
        }

        $value = $this->readNode($json, $property);
        $actual = $this->comparableNode($this->propertyPostProcessValue($property, $value));
        $expectedValue = $this->comparableNode($expectedValue);

        self::assertNotEquals(
            $expectedValue,
            $actual,
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

    public function jsonPropertyDateShouldBeEqualTo(Json $json, string $property, string $expected): void
    {
        $value = $this->readNode($json, $property);

        $expectedTime = (int) $this->dateNode($property, $expected, 'The expected value')->format('U');
        $foundTime = (int) $this->dateNode(
            $property,
            $this->stringNode($property, $value),
            'The node value',
        )->format('U');
        self::assertLessThan(
            self::DATE_TOLERANCE_SECONDS,
            \abs($expectedTime - $foundTime),
            \sprintf(
                'Property %s is %s, which is more than %d seconds from the expected %s',
                $property,
                $this->describeNode($value),
                self::DATE_TOLERANCE_SECONDS,
                $expected,
            ),
        );
    }

    /**
     * Loose containment, mirroring the equality steps: Gherkin delivers the list as text, so a strict
     * comparison would judge a JSON number against a string and answer on the type.
     *
     * Every member goes through the modifier too. Putting only the node through it made the step fail
     * over a node holding literally a listed value, whenever a value-aware modifier claimed the node
     * and rewrote it — a date-shaped node against a date-shaped list, for one.
     */
    public function jsonPropertyShouldBeOneOf(Json $json, string $property, string $list): void
    {
        $value = $this->readNode($json, $property);
        $actual = $this->comparableNode($this->propertyPostProcessValue($property, $value));

        $values = \array_map(
            fn (string $member): mixed => $this->comparableNode(
                $this->propertyPostProcessValue($property, \trim($member)),
            ),
            \explode(',', $list),
        );

        self::assertContainsEquals(
            $actual,
            $values,
            \sprintf('The node value is %s, which is not one of "%s"', $this->describeNode($value), $list),
        );
    }

    /**
     * An operand as a loose comparison may safely see it.
     *
     * Gherkin delivers every expected value as text, so the comparison has to stay loose enough to
     * judge a JSON number against `"5"`. PHP's `==` is far looser than that: with a boolean on either
     * side it coerces the *other* operand to boolean, so `true == "anything"` holds — and a node
     * carrying `true` then satisfies an assertion naming any non-empty text. That reaches further than
     * one step: `OutboxContext::eventMatchesTable()` decides which event a table describes with this
     * comparison, so a table row would match every event whose property is `true`, whatever it asked
     * for.
     *
     * A JSON boolean has exactly two textual forms. Rendering it as one of them keeps the comparison
     * loose where a scenario needs it and closes it where nothing does.
     */
    private function comparableNode(mixed $value): mixed
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_float($value)) {
            return (string) $value;
        }

        return $value;
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
     * Absence reaches here under three exception classes, not two, and the third is the one a
     * root-level selector never produces: the accessor reports a missing *leaf* with
     * {@see NoSuchIndexException} / {@see NoSuchPropertyException}, but a well-formed path whose
     * *parent* holds null or a scalar stops at {@see UnexpectedTypeException} — it has nothing left
     * to traverse. `data.name` over `{"data": null}` is a path the payload does not carry, which is
     * the same unmet expectation as any other, and only `InvalidPropertyPathException` (the selector
     * the accessor cannot parse at all) is the broken step this rethrows for.
     *
     * @throws UnexpectedValueException
     */
    private function rethrowUnlessAbsent(UnexpectedValueException $exception): void
    {
        $cause = $exception->getPrevious();

        if (
            $cause instanceof NoSuchIndexException
            || $cause instanceof NoSuchPropertyException
            || $cause instanceof UnexpectedTypeException
        ) {
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
     * `new DateTime()` rejects a string it cannot parse with a `DateMalformedStringException`, which
     * is not an `AssertionFailedError`: a node holding `true`, `0` or "n/a" would abort the scenario
     * with a raw error where the step's whole subject is that the value is not the date expected.
     */
    /**
     * `new DateTime('')` does not throw: it answers *now*. An empty node would therefore compare equal
     * to `"now"` and land inside the tolerance below, so the guard that exists to reject a non-date
     * would never fire for the one value most likely to arrive by accident.
     */
    private function dateNode(string $property, string $value, string $subject): DateTime
    {
        if ('' === \trim($value)) {
            self::fail(\sprintf('%s of property %s is empty, which is not a date', $subject, $property));
        }

        try {
            return new DateTime($value);
        } catch (DateMalformedStringException) {
            self::fail(\sprintf('%s of property %s is "%s", which is not a date', $subject, $property, $value));
        }
    }

    /**
     * `(string) $value` on an array raises a warning and yields "Array", which then satisfies a
     * "contains" or a pattern that happens to match that word rather than the payload.
     *
     * Booleans are refused for the same reason, not admitted as scalars: `(string) false` is the empty
     * string, and an empty haystack satisfies *every* "should not contain" and matches `/^$/`. `true`
     * fares no better, comparing as `"1"`. A step whose subject is text has no business reading a
     * boolean — {@see booleanNode()} is where one belongs.
     */
    private function stringNode(string $property, mixed $value): string
    {
        if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
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
