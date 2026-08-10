<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support\PostProcess;

use Closure;
use Erpify\Tests\Behat\Support\Json\Json;
use Erpify\Tests\Unit\Behat\Support\PostProcess\Fixtures\JsonAssertions;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * What every node assertion does with a selector the payload does not answer.
 *
 * An absent dot-path is an unmet expectation and must read as one: a raw `UnexpectedValueException`
 * naming the property accessor reads as a broken step instead. The list below is the whole set of node
 * readers on purpose — covering some would split one trait across two contracts, with nothing telling a
 * reader which applies where. `should exist` and `should not exist` are here for the same reason and
 * are read separately: they are the only two that answer the question of presence directly instead of
 * reading a node, so they carry their own copy of the absent-versus-unreadable decision.
 *
 * Two things are kept apart that a single message would blur. A path the payload does not carry is an
 * unmet expectation and reads as a failure; a path the accessor cannot parse is a broken step and keeps
 * its own exception, because calling that "does not exist" sends the next reader hunting a payload
 * field for what is a typo in the selector.
 *
 * {@see CoversNothing} because the subject is test infrastructure — `tests/` sits outside the coverage
 * allowlist, so there is no production line here to credit.
 *
 * @internal
 */
#[CoversNothing]
final class JsonNodeAbsentPathTest extends TestCase
{
    private const string ABSENT = 'absentProperty';

    private const string ABSENT_MESSAGE = 'Property "absentProperty" does not exist in the JSON.';

    private const string PRESENT_PAYLOAD = '{"node": "present"}';

    #[DataProvider('provideAnAbsentPathFailsAsAnAssertionInsteadOfAnAccessorErrorCases')]
    public function testAnAbsentPathFailsAsAnAssertionInsteadOfAnAccessorError(Closure $assertion): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(self::ABSENT_MESSAGE);

        $assertion(JsonAssertions::withScalarModifiers(), new Json(self::PRESENT_PAYLOAD));
    }

    /**
     * @return iterable<string, array{Closure(JsonAssertions, Json): void}>
     */
    public static function provideAnAbsentPathFailsAsAnAssertionInsteadOfAnAccessorErrorCases(): iterable
    {
        yield 'should be equal to' => [
            static function (JsonAssertions $a, Json $j): void {
                $a->jsonPropertyShouldBeEqualTo($j, self::ABSENT, 'x');
            },
        ];
        yield 'should not be equal to' => [
            static function (JsonAssertions $a, Json $j): void {
                $a->jsonPropertyShouldNotBeEqualTo($j, self::ABSENT, 'x');
            },
        ];
        yield 'should be null' => [
            static function (JsonAssertions $a, Json $j): void {
                $a->jsonPropertyShouldBeNull($j, self::ABSENT);
            },
        ];
        yield 'should not be null' => [
            static function (JsonAssertions $a, Json $j): void {
                $a->jsonPropertyShouldNotBeNull($j, self::ABSENT);
            },
        ];
        yield 'should be false' => [
            static function (JsonAssertions $a, Json $j): void {
                $a->jsonPropertyShouldBeFalse($j, self::ABSENT);
            },
        ];
        yield 'should be true' => [
            static function (JsonAssertions $a, Json $j): void {
                $a->jsonPropertyShouldBeTrue($j, self::ABSENT);
            },
        ];
        yield 'should have elements' => [
            static function (JsonAssertions $a, Json $j): void {
                $a->jsonPropertyShouldHaveElements($j, self::ABSENT, 0);
            },
        ];
        yield 'should be typed' => [
            static function (JsonAssertions $a, Json $j): void {
                $a->jsonPropertyShouldBeTyped($j, self::ABSENT, 'string');
            },
        ];
        yield 'should match' => [
            static function (JsonAssertions $a, Json $j): void {
                $a->jsonPropertyShouldMatch($j, self::ABSENT, '/x/');
            },
        ];
        yield 'should contain' => [
            static function (JsonAssertions $a, Json $j): void {
                $a->jsonPropertyShouldContains($j, self::ABSENT, 'x');
            },
        ];
        yield 'should not contain' => [
            static function (JsonAssertions $a, Json $j): void {
                $a->jsonPropertyShouldNotContains($j, self::ABSENT, 'x');
            },
        ];
        yield 'date should be equal to' => [
            static function (JsonAssertions $a, Json $j): void {
                $a->jsonPropertyDateShouldBeEqualTo($j, self::ABSENT, '2026-01-01');
            },
        ];
        yield 'should be one of' => [
            static function (JsonAssertions $a, Json $j): void {
                $a->jsonPropertyShouldBeOneOf($j, self::ABSENT, 'x, y');
            },
        ];
    }

    public function testASelectorTheAccessorCannotParseKeepsItsOwnError(): void
    {
        $this->expectException(UnexpectedValueException::class);

        JsonAssertions::withScalarModifiers()->jsonPropertyShouldBeNull(new Json(self::PRESENT_PAYLOAD), 'node[');
    }

    /**
     * A parent that holds null or a scalar has nothing left to traverse, so the accessor stops on a
     * third exception class rather than on the two a missing leaf produces. The path is still one the
     * payload does not carry, which is why the root-level cases above do not cover this: they are the
     * subset where every reading of "absent" agrees.
     */
    #[DataProvider('provideAPathUnderAParentThatCarriesNoChildrenIsAbsentAndNotABrokenStepCases')]
    public function testAPathUnderAParentThatCarriesNoChildrenIsAbsentAndNotABrokenStep(string $payload): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property "node.child" does not exist in the JSON.');

        JsonAssertions::withScalarModifiers()->jsonPropertyShouldBeNull(new Json($payload), 'node.child');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAPathUnderAParentThatCarriesNoChildrenIsAbsentAndNotABrokenStepCases(): iterable
    {
        yield 'parent holds null' => ['{"node": null}'];
        yield 'parent holds a string' => [self::PRESENT_PAYLOAD];
        yield 'parent holds a number' => ['{"node": 5}'];
    }

    public function testShouldExistFailsAsAnAssertionForAPathThePayloadDoesNotCarry(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The property "absentProperty" does not exist.');

        JsonAssertions::withScalarModifiers()->jsonPropertyShouldExist(new Json(self::PRESENT_PAYLOAD), self::ABSENT);
    }

    public function testShouldExistKeepsItsOwnErrorForASelectorTheAccessorCannotParse(): void
    {
        $this->expectException(UnexpectedValueException::class);

        JsonAssertions::withScalarModifiers()->jsonPropertyShouldExist(new Json(self::PRESENT_PAYLOAD), 'node[');
    }

    public function testShouldNotExistHoldsForAnAbsentPathAndNotForAPresentOne(): void
    {
        $assertions = JsonAssertions::withScalarModifiers();
        $json = new Json(self::PRESENT_PAYLOAD);

        $assertions->jsonPropertyShouldNotExist($json, self::ABSENT);
        $assertions->jsonPropertyShouldNotExist($json, 'node.child');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The property "node" exists.');

        $assertions->jsonPropertyShouldNotExist($json, 'node');
    }

    public function testShouldNotExistKeepsItsOwnErrorForASelectorTheAccessorCannotParse(): void
    {
        $this->expectException(UnexpectedValueException::class);

        JsonAssertions::withScalarModifiers()->jsonPropertyShouldNotExist(new Json(self::PRESENT_PAYLOAD), 'node[');
    }

    public function testAModifierSuffixIsStrippedWithoutMakingANonNullNodePass(): void
    {
        // `node::null` names the node `node` and the way to read the expectation; leaving the suffix on
        // asks the accessor for a property no payload carries, which is an absent node, not a null one.
        JsonAssertions::withScalarModifiers()->jsonPropertyShouldBeNull(new Json('{"node": null}'), 'node::null');

        // Paired with the value the expectation must reject, because stripping the suffix and then
        // asserting nothing would satisfy the accepted case on its own. The message is what pins the
        // stripping: an unstripped suffix fails as an absent path, not as a node that is not null.
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('but it should have been null');

        JsonAssertions::withScalarModifiers()->jsonPropertyShouldBeNull(new Json(self::PRESENT_PAYLOAD), 'node::null');
    }
}
