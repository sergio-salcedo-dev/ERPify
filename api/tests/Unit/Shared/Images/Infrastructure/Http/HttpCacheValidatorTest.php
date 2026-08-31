<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure\Http;

use Erpify\Shared\Images\Infrastructure\Http\HttpCacheValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The three legal spellings of an entity tag plus the wildcard, and the two directions that must NOT match.
 *
 * The weak form is admitted because a `GET` compares with the weak comparison function (RFC 9110 §13.1.2),
 * under which `W/"x"` and `"x"` are a match — accepting it is the specification rather than leniency.
 *
 * @internal
 */
#[CoversClass(HttpCacheValidator::class)]
final class HttpCacheValidatorTest extends TestCase
{
    private const string DIGEST = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';

    #[DataProvider('provideItMatchesEveryLegalSpellingOfTheTagCases')]
    public function testItMatchesEveryLegalSpellingOfTheTag(string $header): void
    {
        $this->assertTrue((new HttpCacheValidator())->isNotModified($this->requestWith($header), self::DIGEST));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideItMatchesEveryLegalSpellingOfTheTagCases(): iterable
    {
        yield 'strong' => ['"' . self::DIGEST . '"'];
        yield 'weak' => ['W/"' . self::DIGEST . '"'];
        yield 'wildcard' => ['*'];
        yield 'among several' => ['"other", W/"' . self::DIGEST . '"'];
    }

    #[DataProvider('provideItRefusesAnythingThatIsNotThisTagCases')]
    public function testItRefusesAnythingThatIsNotThisTag(string $header): void
    {
        $this->assertFalse((new HttpCacheValidator())->isNotModified($this->requestWith($header), self::DIGEST));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideItRefusesAnythingThatIsNotThisTagCases(): iterable
    {
        yield 'a different tag' => ['"0000000000000000000000000000000000000000000000000000000000000000"'];
        yield 'an empty header' => [''];
        yield 'a prefix of the tag' => ['"' . \substr(self::DIGEST, 0, 32) . '"'];
        // The quotes are part of the entity-tag grammar, so this is not a malformed tag but no tag at all.
        yield 'the right digest, unquoted' => [self::DIGEST];
    }

    public function testWithNoConditionalHeaderAtAllTheResponseIsAlwaysModified(): void
    {
        $this->assertFalse((new HttpCacheValidator())->isNotModified(new Request(), self::DIGEST));
    }

    private function requestWith(string $header): Request
    {
        $request = new Request();
        $request->headers->set('If-None-Match', $header);

        return $request;
    }
}
