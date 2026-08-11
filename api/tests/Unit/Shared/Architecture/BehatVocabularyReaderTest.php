<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Unit\Shared\Architecture\Support\BehatVocabularyReader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The three places where the reader's hand-written translation of Behat's pattern syntax used to
 * disagree with Behat, driven over the live tree.
 *
 * Each disagreement is silent in the same direction: a pattern Behat dispatches reads here as reached
 * by nothing, so a `used` classification reds for no reason and — worse — an `idle` one stays green
 * over a pattern scenarios really use. The gate's counts are only worth what this agreement is worth.
 *
 * The expectations are anchored on patterns the tree actually declares, so a rename shows up here
 * rather than leaving the test asserting against a string nothing uses.
 *
 * {@see CoversNothing} because the subject is test infrastructure — `tests/` sits outside the coverage
 * allowlist, so there is no production line here to credit.
 *
 * @internal
 */
#[CoversNothing]
final class BehatVocabularyReaderTest extends TestCase
{
    #[DataProvider('provideAStepResolvesRegardlessOfCaseLikeBehatCases')]
    public function testAStepResolvesRegardlessOfCaseLikeBehat(string $step): void
    {
        $this->assertSame([], $this->reader()->unresolvedStepsAmong([$step]));
    }

    /**
     * Behat's turnip regex closes with `/iu`, so it dispatches a step whose keyword differs only in
     * case. A case-sensitive reader reported the pattern as unreached.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideAStepResolvesRegardlessOfCaseLikeBehatCases(): iterable
    {
        yield 'as written' => ['The outbox event should be of type "X"'];
        yield 'lower-cased first word' => ['the outbox event should be of type "X"'];
        yield 'upper-cased first word' => ['THE outbox event should be of type "X"'];
    }

    /**
     * `str_starts_with($pattern, '/')` is not Behat's test for "already a regex", and it was wrong in
     * both directions: a turnip pattern beginning with a path segment was handed to `preg_match` as if
     * it were one, and a `#…#`-delimited regex was quoted as literal text. Either way the pattern
     * matched nothing at all.
     */
    public function testATurnipPatternThatLooksLikeAPathIsNotTreatedAsARegex(): void
    {
        $this->assertSame(
            [],
            $this->reader()->unresolvedStepsAmong(['I send a "GET" request to "/backoffice/health"']),
        );
    }

    /**
     * Behat's lexer opens a docstring on ``` as well as on three double quotes. A reader that knows
     * only one of them harvests every prose line inside the other as if it were a step — and those
     * lines start with step keywords precisely because the prose is about steps.
     */
    public function testAFencedDocstringIsNotHarvestedAsSteps(): void
    {
        $apiRoot = \dirname(__DIR__, 4);
        $reader = new BehatVocabularyReader(
            $apiRoot . '/tests/Behat',
            __DIR__ . '/Fixture/VocabularyFeatures',
        );

        $steps = $reader->featureSteps();

        $this->assertContains('I am logged in as an admin', $steps, 'the real steps must still be read');
        $this->assertNotContains('this line is prose inside a fenced docstring', $steps);
        $this->assertNotContains('so is this one', $steps);
    }

    public function testTheTreeItReadsIsNotEmpty(): void
    {
        $this->assertNotEmpty($this->reader()->declaredPatterns());
        $this->assertNotEmpty($this->reader()->featureSteps());
        $this->assertSame([], $this->reader()->unresolvedSteps());
    }

    private function reader(): BehatVocabularyReader
    {
        $apiRoot = \dirname(__DIR__, 4);

        return new BehatVocabularyReader($apiRoot . '/tests/Behat', $apiRoot . '/features');
    }
}
