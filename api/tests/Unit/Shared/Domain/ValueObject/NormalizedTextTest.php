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
        $vo = NormalizedText::from('BBVA');

        $this->assertSame('BBVA', $vo->display);
        $this->assertSame('bbva', $vo->normalized);
    }

    public function testTrimsSurroundingWhitespaceFromBothHalves(): void
    {
        $vo = NormalizedText::from("  ING Direct \t");

        $this->assertSame('ING Direct', $vo->display);
        $this->assertSame('ing direct', $vo->normalized);
    }

    public function testStripsDiacriticalMarksFromNormalizedHalfButNotFromDisplay(): void
    {
        $vo = NormalizedText::from('Sociedad Anónima');

        $this->assertSame('Sociedad Anónima', $vo->display);
        $this->assertSame('sociedad anonima', $vo->normalized);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function equalityCases(): iterable
    {
        yield 'identical inputs' => ['BBVA', 'BBVA', true];
        yield 'differs only in case' => ['BBVA', 'bbva', true];
        yield 'differs only in surrounding whitespace' => ['BBVA', '  bbva  ', true];
        yield 'differs only in diacritics' => ['Sociedad Anónima', 'Sociedad Anonima', true];
        yield 'differs in case and diacritics' => ['Société Générale', 'societe generale', true];
        yield 'distinct names' => ['BBVA', 'Santander', false];
    }

    #[DataProvider('equalityCases')]
    public function testEqualsIgnoresCaseWhitespaceAndDiacritics(string $a, string $b, bool $expected): void
    {
        $this->assertSame($expected, NormalizedText::from($a)->equals(NormalizedText::from($b)));
    }

    public function testNormalizeMatchesFromOutput(): void
    {
        $raw = '  Banco Sabadell ';

        $this->assertSame(NormalizedText::from($raw)->normalized, NormalizedText::normalize($raw));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function asciiUpperCases(): iterable
    {
        yield 'lower-case to upper' => ['bbva', 'BBVA'];
        yield 'mixed-case to upper' => ['BbVa', 'BBVA'];
        yield 'strips diacritics' => ['GLÉ', 'GLE'];
        yield 'strips diacritics and lowers' => ['glé', 'GLE'];
        yield 'trims surrounding whitespace' => ['  bnp  ', 'BNP'];
        yield 'numbers preserved' => ['Bk7', 'BK7'];
    }

    #[DataProvider('asciiUpperCases')]
    public function testToAsciiUpperProducesCanonicalCode(string $raw, string $expected): void
    {
        $this->assertSame($expected, NormalizedText::toAsciiUpper($raw));
    }
}
