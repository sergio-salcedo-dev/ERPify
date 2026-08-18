<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support\PostProcess;

use Erpify\Tests\Behat\Support\Json\Json;
use Erpify\Tests\Unit\Behat\Support\PostProcess\Fixtures\JsonAssertions;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two assertions whose subject is the *shape* of a node, judged on values that are not that shape.
 *
 * Both have a cheap wrong answer. Counting `(array) $value` counts the cast, which wraps a scalar into
 * a one-element array and turns null into an empty one, so a collection assertion would hold for values
 * that are not collections. Filtering through `FILTER_VALIDATE_BOOLEAN` maps everything it does not
 * recognise to false, so a boolean assertion would hold for "", 0, null, [] and any unrecognised string.
 * Each is green over a value that never carried the property being asserted.
 *
 * The steps whose subject is the comparison rather than the shape are read in
 * {@see JsonNodeValueComparisonTest}.
 *
 * Every case is paired with the value the step is supposed to accept, because an assertion that
 * rejected everything would satisfy the first half on its own.
 *
 * {@see CoversNothing} because the subject is test infrastructure — `tests/` sits outside the coverage
 * allowlist, so there is no production line here to credit.
 *
 * @internal
 */
#[CoversNothing]
final class JsonNodeShapeTest extends TestCase
{
    private const string NODE = 'node';

    public function testZeroElementsDoesNotHoldForAnExplicitNull(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('holds null, which is not a collection');

        JsonAssertions::withScalarModifiers()
            ->jsonPropertyShouldHaveElements(new Json('{"node": null}'), self::NODE, 0)
        ;
    }

    public function testOneElementDoesNotHoldForAScalar(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('holds string, which is not a collection');

        JsonAssertions::withScalarModifiers()
            ->jsonPropertyShouldHaveElements(new Json('{"node": "scalar"}'), self::NODE, 1)
        ;
    }

    public function testElementsAreCountedForAListAndForAnObjectAndNotMiscounted(): void
    {
        $assertions = JsonAssertions::withScalarModifiers();

        $assertions->jsonPropertyShouldHaveElements(new Json('{"node": [1, 2, 3]}'), self::NODE, 3);
        $assertions->jsonPropertyShouldHaveElements(new Json('{"node": {"a": 1, "b": 2}}'), self::NODE, 2);

        // The cases above are the accepted half; only a wrong count over a real collection shows the
        // comparison is made at all, rather than the guard above being the whole assertion.
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('has 3 children whereas it should have 2');

        $assertions->jsonPropertyShouldHaveElements(new Json('{"node": [1, 2, 3]}'), self::NODE, 2);
    }

    #[DataProvider('nonBooleanNodes')]
    public function testFalseDoesNotHoldForANodeThatIsNotABoolean(string $payload): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('which is not a boolean');

        JsonAssertions::withScalarModifiers()->jsonPropertyShouldBeFalse(new Json($payload), self::NODE);
    }

    #[DataProvider('nonBooleanNodes')]
    public function testTrueDoesNotHoldForANodeThatIsNotABoolean(string $payload): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('which is not a boolean');

        JsonAssertions::withScalarModifiers()->jsonPropertyShouldBeTrue(new Json($payload), self::NODE);
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

    public function testFalseHoldsForABooleanFalseAndNotForABooleanTrue(): void
    {
        JsonAssertions::withScalarModifiers()->jsonPropertyShouldBeFalse(new Json('{"node": false}'), self::NODE);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('is true but it should have been false');

        JsonAssertions::withScalarModifiers()->jsonPropertyShouldBeFalse(new Json('{"node": true}'), self::NODE);
    }

    public function testTrueHoldsForABooleanTrueAndNotForABooleanFalse(): void
    {
        JsonAssertions::withScalarModifiers()->jsonPropertyShouldBeTrue(new Json('{"node": true}'), self::NODE);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('is false but it should have been true');

        JsonAssertions::withScalarModifiers()->jsonPropertyShouldBeTrue(new Json('{"node": false}'), self::NODE);
    }

    public function testTheTypeAssertionHoldsForTheRightTypeAndNotForAnother(): void
    {
        $assertions = JsonAssertions::withScalarModifiers();

        $assertions->jsonPropertyShouldBeTyped(new Json('{"node": "text"}'), self::NODE, 'string');
        $assertions->jsonPropertyShouldBeTyped(new Json('{"node": 7}'), self::NODE, 'integer');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('is typed integer whereas it should have been string');

        $assertions->jsonPropertyShouldBeTyped(new Json('{"node": 7}'), self::NODE, 'string');
    }
}
