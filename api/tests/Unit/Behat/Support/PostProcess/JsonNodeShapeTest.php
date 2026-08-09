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
 * The two assertions that used to hold for values which are not the shape they assert about.
 *
 * `should have N elements` counted `(array) $value`, and the cast wraps a scalar into a one-element
 * array and turns null into an empty one — so "should have 1 element" held for any scalar and
 * "should have 0 elements" held for an explicit null. `should be true` / `should be false` filtered
 * through `FILTER_VALIDATE_BOOLEAN`, which maps everything it does not recognise to false: "", 0, null,
 * [] and any unrecognised string all satisfied "should be false", and "yes" satisfied "should be true".
 *
 * Both failed the same way — green over a value that never had the property being asserted. Each case
 * is paired with the value the step is supposed to accept, because an assertion that rejected
 * everything would satisfy the first half on its own.
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
        $this->expectExceptionMessage('holds null, which is not a collection');

        JsonAssertions::withScalarModifiers()
            ->jsonPropertyShouldHaveElements(new Json('{"node": null}'), self::NODE, 0)
        ;
    }

    public function testOneElementDoesNotHoldForAScalar(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('holds string, which is not a collection');

        JsonAssertions::withScalarModifiers()
            ->jsonPropertyShouldHaveElements(new Json('{"node": "scalar"}'), self::NODE, 1)
        ;
    }

    public function testElementsAreCountedForAListAndForAnObject(): void
    {
        $assertions = JsonAssertions::withScalarModifiers();

        $assertions->jsonPropertyShouldHaveElements(new Json('{"node": [1, 2, 3]}'), self::NODE, 3);
        $assertions->jsonPropertyShouldHaveElements(new Json('{"node": {"a": 1, "b": 2}}'), self::NODE, 2);
    }

    #[DataProvider('nonBooleanNodes')]
    public function testFalseDoesNotHoldForANodeThatIsNotABoolean(string $payload): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('which is not a boolean');

        JsonAssertions::withScalarModifiers()->jsonPropertyShouldBeFalse(new Json($payload), self::NODE);
    }

    #[DataProvider('nonBooleanNodes')]
    public function testTrueDoesNotHoldForANodeThatIsNotABoolean(string $payload): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('which is not a boolean');

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
        $this->expectExceptionMessage('is true but it should have been false');

        JsonAssertions::withScalarModifiers()->jsonPropertyShouldBeFalse(new Json('{"node": true}'), self::NODE);
    }

    public function testTrueHoldsForABooleanTrueAndNotForABooleanFalse(): void
    {
        JsonAssertions::withScalarModifiers()->jsonPropertyShouldBeTrue(new Json('{"node": true}'), self::NODE);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('is false but it should have been true');

        JsonAssertions::withScalarModifiers()->jsonPropertyShouldBeTrue(new Json('{"node": false}'), self::NODE);
    }
}
