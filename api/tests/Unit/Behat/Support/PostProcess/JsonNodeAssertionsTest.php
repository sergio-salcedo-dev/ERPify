<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support\PostProcess;

use Behat\Gherkin\Node\PyStringNode;
use Closure;
use Erpify\Tests\Behat\NodeModifier\NodeModifierLocator;
use Erpify\Tests\Behat\NodeModifier\Scalar\NullNodeModifier;
use Erpify\Tests\Behat\NodeModifier\Scalar\StringNodeModifier;
use Erpify\Tests\Behat\Support\Json\Json;
use Erpify\Tests\Behat\Support\Json\JsonSchema;
use Erpify\Tests\Unit\Behat\Support\PostProcess\Fixtures\JsonAssertions;
use JsonSchema\Exception\RuntimeException as JsonSchemaException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * The node assertions the whole acceptance suite is built on, judged on the one question a scenario
 * cannot ask: can this step fail at all?
 *
 * Each case below pairs a value the step used to accept with the value it is supposed to accept. The
 * pairing is the point — an assertion that rejects everything satisfies the first half on its own, and
 * a file holding only the first half would prove the steps are broken rather than strict.
 *
 * Two node modifiers, not the container's set: neither claims a value by auto-detection, so a value
 * reaches the comparison as written and the subject stays the assertion rather than modifier
 * resolution. `null` is registered because the suffix-stripping cases name it explicitly.
 *
 * {@see CoversNothing} because the subject is test infrastructure — `tests/` sits outside the coverage
 * allowlist, so there is no production line here to credit.
 *
 * @internal
 */
#[CoversNothing]
final class JsonNodeAssertionsTest extends TestCase
{
    private const string ABSENT = 'absentProperty';

    private const string ABSENT_MESSAGE = 'Property "absentProperty" does not exist in the JSON.';

    /**
     * Every public assertion that reads a node. An absent dot-path used to leave each of them as a raw
     * `UnexpectedValueException` naming the property accessor, which reads as a broken step rather than
     * as the unmet expectation it is — and the list is the whole set on purpose, because fixing some of
     * them would split one trait across two contracts with nothing telling a reader which applies.
     *
     * @return iterable<string, array{Closure(JsonAssertions, Json): void}>
     */
    public static function nodeReadingAssertions(): iterable
    {
        yield 'should be equal to' => [
            static function (JsonAssertions $a, Json $j): void { $a->jsonPropertyShouldBeEqualTo($j, self::ABSENT, 'x'); },
        ];
        yield 'should not be equal to' => [
            static function (JsonAssertions $a, Json $j): void { $a->jsonPropertyShouldNotBeEqualTo($j, self::ABSENT, 'x'); },
        ];
        yield 'should be null' => [
            static function (JsonAssertions $a, Json $j): void { $a->jsonPropertyShouldBeNull($j, self::ABSENT); },
        ];
        yield 'should not be null' => [
            static function (JsonAssertions $a, Json $j): void { $a->jsonPropertyShouldNotBeNull($j, self::ABSENT); },
        ];
        yield 'should be false' => [
            static function (JsonAssertions $a, Json $j): void { $a->jsonPropertyShouldBeFalse($j, self::ABSENT); },
        ];
        yield 'should be true' => [
            static function (JsonAssertions $a, Json $j): void { $a->jsonPropertyShouldBeTrue($j, self::ABSENT); },
        ];
        yield 'should have elements' => [
            static function (JsonAssertions $a, Json $j): void { $a->jsonPropertyShouldHaveElements($j, self::ABSENT, 0); },
        ];
        yield 'should be typed' => [
            static function (JsonAssertions $a, Json $j): void { $a->jsonPropertyShouldBeTyped($j, self::ABSENT, 'string'); },
        ];
        yield 'should match' => [
            static function (JsonAssertions $a, Json $j): void { $a->jsonPropertyShouldMatch($j, self::ABSENT, '/x/'); },
        ];
        yield 'should contain' => [
            static function (JsonAssertions $a, Json $j): void { $a->jsonPropertyShouldContains($j, self::ABSENT, 'x'); },
        ];
        yield 'should not contain' => [
            static function (JsonAssertions $a, Json $j): void { $a->jsonPropertyShouldNotContains($j, self::ABSENT, 'x'); },
        ];
        yield 'date should be equal to' => [
            static function (JsonAssertions $a, Json $j): void {
                $a->jsonPropertyDateShouldBeEqualTo($j, self::ABSENT, '2026-01-01');
            },
        ];
        yield 'should be one of' => [
            static function (JsonAssertions $a, Json $j): void { $a->jsonPropertyShouldBeOneOf($j, self::ABSENT, 'x, y'); },
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonBooleanNodes(): iterable
    {
        yield 'empty string' => ['{"node": ""}'];
        yield 'zero' => ['{"node": 0}'];
        yield 'null' => ['{"node": null}'];
        yield 'empty array' => ['{"node": []}'];
        yield 'unrecognised string' => ['{"node": "banana"}'];
        yield 'string "yes"' => ['{"node": "yes"}'];
        yield 'string "1"' => ['{"node": "1"}'];
    }

    #[DataProvider('nodeReadingAssertions')]
    public function testAnAbsentPathFailsAsAnAssertionInsteadOfAnAccessorError(Closure $assertion): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(self::ABSENT_MESSAGE);

        $assertion($this->assertions(), $this->json('{"node": "present"}'));
    }

    public function testASelectorTheAccessorCannotParseKeepsItsOwnError(): void
    {
        // Absence is an unmet expectation; an unparseable selector is a broken step. Reporting the
        // second as the first would send the reader looking for a payload field over a typo.
        $this->expectException(UnexpectedValueException::class);

        $this->assertions()->jsonPropertyShouldBeNull($this->json('{"node": "present"}'), 'node[');
    }

    public function testZeroElementsDoesNotHoldForAnExplicitNull(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('holds null, which is not a collection');

        $this->assertions()->jsonPropertyShouldHaveElements($this->json('{"node": null}'), 'node', 0);
    }

    public function testOneElementDoesNotHoldForAScalar(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('holds string, which is not a collection');

        $this->assertions()->jsonPropertyShouldHaveElements($this->json('{"node": "scalar"}'), 'node', 1);
    }

    public function testElementsAreCountedForAList(): void
    {
        $this->assertions()->jsonPropertyShouldHaveElements($this->json('{"node": [1, 2, 3]}'), 'node', 3);
    }

    public function testElementsAreCountedForAnObject(): void
    {
        $this->assertions()->jsonPropertyShouldHaveElements($this->json('{"node": {"a": 1, "b": 2}}'), 'node', 2);
    }

    #[DataProvider('nonBooleanNodes')]
    public function testFalseDoesNotHoldForANodeThatIsNotABoolean(string $payload): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('which is not a boolean');

        $this->assertions()->jsonPropertyShouldBeFalse($this->json($payload), 'node');
    }

    #[DataProvider('nonBooleanNodes')]
    public function testTrueDoesNotHoldForANodeThatIsNotABoolean(string $payload): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('which is not a boolean');

        $this->assertions()->jsonPropertyShouldBeTrue($this->json($payload), 'node');
    }

    public function testFalseHoldsForABooleanFalseAndNotForABooleanTrue(): void
    {
        $this->assertions()->jsonPropertyShouldBeFalse($this->json('{"node": false}'), 'node');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('is true but it should have been false');

        $this->assertions()->jsonPropertyShouldBeFalse($this->json('{"node": true}'), 'node');
    }

    public function testTrueHoldsForABooleanTrueAndNotForABooleanFalse(): void
    {
        $this->assertions()->jsonPropertyShouldBeTrue($this->json('{"node": true}'), 'node');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('is false but it should have been true');

        $this->assertions()->jsonPropertyShouldBeTrue($this->json('{"node": false}'), 'node');
    }

    public function testAModifierSuffixNeverReachesThePropertyAccessor(): void
    {
        // `node::null` names the node `node` and the way to read the expectation; leaving the suffix on
        // asks the accessor for a property no payload carries, which is an absent node, not a null one.
        $this->assertions()->jsonPropertyShouldBeNull($this->json('{"node": null}'), 'node::null');
    }

    public function testAModifierSuffixDoesNotMakeANonNullNodePass(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('but it should have been null');

        $this->assertions()->jsonPropertyShouldBeNull($this->json('{"node": "present"}'), 'node::null');
    }

    public function testNotValidHoldsWhenTheSchemaRejectsTheDocument(): void
    {
        $this->expectNotToPerformAssertions();

        $this->assertions()->jsonShouldNotBeValid(
            $this->json('{"node": "a string"}'),
            $this->schema('{"type": "object", "properties": {"node": {"type": "integer"}}}'),
        );
    }

    public function testNotValidFailsWhenTheSchemaAcceptsTheDocument(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Given JSON validates the schema');

        $this->assertions()->jsonShouldNotBeValid(
            $this->json('{"node": "a string"}'),
            $this->schema('{"type": "object", "properties": {"node": {"type": "string"}}}'),
        );
    }

    public function testNotValidDoesNotHoldWhenTheSchemaWasNeverLoaded(): void
    {
        // The step used to accept any exception as "not valid", so it passed hardest exactly when it
        // had checked least: an unreachable schema satisfied it as convincingly as a rejected payload.
        $this->expectException(JsonSchemaException::class);

        $this->assertions()->jsonShouldNotBeValid(
            $this->json('{"node": "a string"}'),
            new JsonSchema(new PyStringNode(['{}'], 0), 'file:///erpify/no/such/schema.json'),
        );
    }

    private function assertions(): JsonAssertions
    {
        $assertions = new JsonAssertions();
        $assertions->setNodeModifierLocator(
            new NodeModifierLocator([new NullNodeModifier(), new StringNodeModifier()]),
        );

        return $assertions;
    }

    private function json(string $payload): Json
    {
        return new Json($payload);
    }

    private function schema(string $payload): JsonSchema
    {
        return new JsonSchema(new PyStringNode([$payload], 0));
    }
}
