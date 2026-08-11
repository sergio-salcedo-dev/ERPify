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
 * The two assertions that compare a node against something other than a literal, and where the
 * comparison itself is what can be got wrong.
 *
 * A date step hands the node to `new DateTime()`, which rejects what it cannot parse with an error
 * that is not an assertion failure — so a node holding `true` or "n/a" would abort the scenario
 * instead of reporting the mismatch the step exists to report. A "one of" step receives its accepted
 * set as text, because that is all Gherkin delivers, so comparing strictly answers on the type: the
 * number 1 would not be one of "1, 2", and the step would fail hardest exactly where the payload
 * carries a value the list names.
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
final class JsonNodeValueComparisonTest extends TestCase
{
    private const string NODE = 'node';

    #[DataProvider('provideADateComparisonOverAValueThatIsNotADateFailsAsAnAssertionCases')]
    public function testADateComparisonOverAValueThatIsNotADateFailsAsAnAssertion(string $payload): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('which is not a date');

        JsonAssertions::withScalarModifiers()
            ->jsonPropertyDateShouldBeEqualTo(new Json($payload), self::NODE, '2026-01-01')
        ;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideADateComparisonOverAValueThatIsNotADateFailsAsAnAssertionCases(): iterable
    {
        yield 'unparseable string' => ['{"node": "n/a"}'];
        yield 'empty string' => ['{"node": ""}'];
    }

    /**
     * A boolean is refused a step earlier, by the string guard, and that is the right place: `(string)
     * false` is the empty string and `new DateTime('')` answers *now* without complaint, so a boolean
     * that reached the date parse would be read as the current time rather than rejected.
     */
    public function testADateComparisonOverABooleanIsRefusedBeforeItReachesTheDateParse(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('which has no string form to compare');

        JsonAssertions::withScalarModifiers()
            ->jsonPropertyDateShouldBeEqualTo(new Json('{"node": true}'), self::NODE, '2026-01-01')
        ;
    }

    public function testADateComparisonStillHoldsForARealDateAndNotForAnotherOne(): void
    {
        $assertions = JsonAssertions::withScalarModifiers();

        $assertions->jsonPropertyDateShouldBeEqualTo(new Json('{"node": "2026-01-01"}'), self::NODE, '2026-01-01');

        $this->expectException(AssertionFailedError::class);

        $assertions->jsonPropertyDateShouldBeEqualTo(new Json('{"node": "2026-01-02"}'), self::NODE, '2026-01-01');
    }

    public function testBeingOneOfComparesByValueAndNotByType(): void
    {
        $assertions = JsonAssertions::withScalarModifiers();

        $assertions->jsonPropertyShouldBeOneOf(new Json('{"node": 1}'), self::NODE, '1, 2');
        $assertions->jsonPropertyShouldBeOneOf(new Json('{"node": "open"}'), self::NODE, 'open, closed');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('which is not one of "1, 2"');

        $assertions->jsonPropertyShouldBeOneOf(new Json('{"node": 3}'), self::NODE, '1, 2');
    }
}
