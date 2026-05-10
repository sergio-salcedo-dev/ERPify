<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\ValueObject;

use Erpify\Shared\Domain\ValueObject\NormalizedText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(NormalizedText::class)]
final class NormalizedTextTest extends TestCase
{
    public function testPreservesDisplayCasing(): void
    {
        $normalizedText = NormalizedText::from('BBVA');

        $this->assertSame('BBVA', $normalizedText->display);
        $this->assertSame('bbva', $normalizedText->normalized);
    }

    public function testTrimsSurroundingWhitespaceFromBothHalves(): void
    {
        $normalizedText = NormalizedText::from("  ING Direct \t");

        $this->assertSame('ING Direct', $normalizedText->display);
        $this->assertSame('ing direct', $normalizedText->normalized);
    }

    public function testStripsDiacriticalMarksFromNormalizedHalfButNotFromDisplay(): void
    {
        $normalizedText = NormalizedText::from('Sociedad Anónima');

        $this->assertSame('Sociedad Anónima', $normalizedText->display);
        $this->assertSame('sociedad anonima', $normalizedText->normalized);
    }

    #[DataProvider('provideEqualsIgnoresCaseWhitespaceAndDiacriticsCases')]
    public function testEqualsIgnoresCaseWhitespaceAndDiacritics(string $a, string $b, bool $expected): void
    {
        $this->assertSame($expected, NormalizedText::from($a)->equals(NormalizedText::from($b)));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideEqualsIgnoresCaseWhitespaceAndDiacriticsCases(): iterable
    {
        yield 'identical inputs' => ['BBVA', 'BBVA', true];
        yield 'differs only in case' => ['BBVA', 'bbva', true];
        yield 'differs only in surrounding whitespace' => ['BBVA', '  bbva  ', true];
        yield 'differs only in diacritics' => ['Sociedad Anónima', 'Sociedad Anonima', true];
        yield 'differs in case and diacritics' => ['Société Générale', 'societe generale', true];
        yield 'distinct names' => ['BBVA', 'Santander', false];
    }

    public function testNormalizeMatchesFromOutput(): void
    {
        $raw = '  Banco Sabadell ';

        $this->assertSame(NormalizedText::from($raw)->normalized, NormalizedText::normalize($raw));
    }

    #[DataProvider('provideToAsciiUpperProducesCanonicalCodeCases')]
    public function testToAsciiUpperProducesCanonicalCode(string $raw, string $expected): void
    {
        $this->assertSame($expected, NormalizedText::toAsciiUpper($raw));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideToAsciiUpperProducesCanonicalCodeCases(): iterable
    {
        yield 'lower-case to upper' => ['bbva', 'BBVA'];
        yield 'mixed-case to upper' => ['BbVa', 'BBVA'];
        yield 'strips diacritics' => ['GLÉ', 'GLE'];
        yield 'strips diacritics and lowers' => ['glé', 'GLE'];
        yield 'trims surrounding whitespace' => ['  bnp  ', 'BNP'];
        yield 'numbers preserved' => ['Bk7', 'BK7'];
    }
}
