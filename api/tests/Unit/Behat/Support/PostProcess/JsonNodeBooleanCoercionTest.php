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
 * What the comparison steps do when the node holds a JSON boolean.
 *
 * PHP's `==` is not "loose about text and numbers" — with a boolean on either side it coerces the other
 * operand to boolean, so `true == "anything"` holds. Every step here compares a node against a value
 * Gherkin delivered as text, so a node carrying `true` would satisfy an assertion naming any non-empty
 * string, and one carrying `false` would satisfy one naming `""` or `"0"`.
 *
 * The reach is wider than the steps. `OutboxContext::eventMatchesTable()` decides which event a table
 * describes by running the equality step and catching what it throws, so a table row would match every
 * event whose property is `true`, whatever the row asked for — in the negative form, silently.
 *
 * The text steps have the mirror of the same problem: `(string) false` is the empty string, and an
 * empty haystack satisfies every "should not contain" and matches `/^$/`.
 *
 * Each case is paired with the value the step is supposed to accept, because an assertion that rejected
 * every boolean would satisfy the first half on its own.
 *
 * {@see CoversNothing} because the subject is test infrastructure — `tests/` sits outside the coverage
 * allowlist, so there is no production line here to credit.
 *
 * @internal
 */
#[CoversNothing]
final class JsonNodeBooleanCoercionTest extends TestCase
{
    private const string NODE = 'node';

    private const string TRUE_PAYLOAD = '{"node": true}';

    private const string FALSE_PAYLOAD = '{"node": false}';

    #[DataProvider('provideABooleanNodeDoesNotEqualTextThatIsNotItsOwnSpellingCases')]
    public function testABooleanNodeDoesNotEqualTextThatIsNotItsOwnSpelling(string $payload, string $text): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('but');

        JsonAssertions::withScalarModifiers()->jsonPropertyShouldBeEqualTo(new Json($payload), self::NODE, $text);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideABooleanNodeDoesNotEqualTextThatIsNotItsOwnSpellingCases(): iterable
    {
        yield 'true against arbitrary text' => [self::TRUE_PAYLOAD, 'anything at all'];
        yield 'true against a number' => [self::TRUE_PAYLOAD, '7'];
        yield 'false against the empty string' => [self::FALSE_PAYLOAD, ''];
        yield 'false against zero' => [self::FALSE_PAYLOAD, '0'];
    }

    public function testABooleanNodeEqualsItsOwnSpellingAndNotTheOther(): void
    {
        $assertions = JsonAssertions::withScalarModifiers();

        $assertions->jsonPropertyShouldBeEqualTo(new Json(self::TRUE_PAYLOAD), self::NODE, 'true');
        $assertions->jsonPropertyShouldBeEqualTo(new Json(self::FALSE_PAYLOAD), self::NODE, 'false');

        $this->expectException(AssertionFailedError::class);

        $assertions->jsonPropertyShouldBeEqualTo(new Json(self::TRUE_PAYLOAD), self::NODE, 'false');
    }

    /**
     * The direction where the coercion produced a false *failure* rather than a false pass: the step
     * reported a boolean node as equal to text it has nothing to do with, and so refused to hold.
     */
    public function testTheNegativeEqualityHoldsForTextABooleanIsNot(): void
    {
        JsonAssertions::withScalarModifiers()
            ->jsonPropertyShouldNotBeEqualTo(new Json(self::TRUE_PAYLOAD), self::NODE, 'anything at all')
        ;

        $this->expectException(AssertionFailedError::class);

        JsonAssertions::withScalarModifiers()
            ->jsonPropertyShouldNotBeEqualTo(new Json(self::TRUE_PAYLOAD), self::NODE, 'true')
        ;
    }

    public function testABooleanNodeIsNotOneOfAListItDoesNotName(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('which is not one of');

        JsonAssertions::withScalarModifiers()
            ->jsonPropertyShouldBeOneOf(new Json(self::TRUE_PAYLOAD), self::NODE, 'open, closed')
        ;
    }

    public function testABooleanNodeIsOneOfAListThatNamesIt(): void
    {
        JsonAssertions::withScalarModifiers()
            ->jsonPropertyShouldBeOneOf(new Json(self::TRUE_PAYLOAD), self::NODE, 'true, false')
        ;

        $this->expectException(AssertionFailedError::class);

        JsonAssertions::withScalarModifiers()
            ->jsonPropertyShouldBeOneOf(new Json(self::FALSE_PAYLOAD), self::NODE, 'true')
        ;
    }

    public function testAListMemberIsPutThroughTheModifierLikeTheNode(): void
    {
        // A value-aware modifier claims the node and rewrites it; leaving the list raw made the step
        // fail over a node holding literally one of the listed values.
        JsonAssertions::withScalarModifiers()
            ->jsonPropertyShouldBeOneOf(new Json('{"node": "b"}'), self::NODE, 'a, b, c')
        ;

        $this->expectException(AssertionFailedError::class);

        JsonAssertions::withScalarModifiers()
            ->jsonPropertyShouldBeOneOf(new Json('{"node": "d"}'), self::NODE, 'a, b, c')
        ;
    }

    #[DataProvider('provideTheTextStepsRefuseABooleanRatherThanStringifyingItCases')]
    public function testTheTextStepsRefuseABooleanRatherThanStringifyingIt(string $payload): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('which has no string form to compare');

        JsonAssertions::withScalarModifiers()
            ->jsonPropertyShouldNotContains(new Json($payload), self::NODE, 'anything')
        ;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTheTextStepsRefuseABooleanRatherThanStringifyingItCases(): iterable
    {
        yield 'true' => [self::TRUE_PAYLOAD];
        yield 'false' => [self::FALSE_PAYLOAD];
    }

    public function testTheTextStepsStillReadNumbersAndStrings(): void
    {
        $assertions = JsonAssertions::withScalarModifiers();

        $assertions->jsonPropertyShouldContains(new Json('{"node": "a haystack"}'), self::NODE, 'hay');
        $assertions->jsonPropertyShouldContains(new Json('{"node": 1234}'), self::NODE, '23');
        $assertions->jsonPropertyShouldNotContains(new Json('{"node": "a haystack"}'), self::NODE, 'needle');

        // The pairing is what makes the three calls above assertions rather than three no-ops: a step
        // that refused every value would satisfy them just as well, so the same step over a needle the
        // node does not carry has to fail.
        $this->expectException(AssertionFailedError::class);

        $assertions->jsonPropertyShouldContains(new Json('{"node": "a haystack"}'), self::NODE, 'needle');
    }
}
