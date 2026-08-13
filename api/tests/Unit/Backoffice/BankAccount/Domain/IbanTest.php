<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain;

use Erpify\Backoffice\BankAccount\Domain\Iban;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The canonical form has to absorb every separator the validator gating the field already absorbs.
 * Anything narrower accepts a value at the edge and persists a spelling the column's own uniqueness
 * cannot see as a duplicate — the two non-ASCII spaces below are what a copy-paste out of a bank
 * statement or a PDF actually carries, and both pass `Assert\Iban`.
 *
 * @internal
 */
#[CoversClass(Iban::class)]
final class IbanTest extends TestCase
{
    #[DataProvider('provideItReducesEveryAcceptedSpellingToOneFormCases')]
    public function testItReducesEveryAcceptedSpellingToOneForm(string $input, string $expected): void
    {
        $this->assertSame($expected, Iban::canonicalize($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideItReducesEveryAcceptedSpellingToOneFormCases(): iterable
    {
        $canonical = 'AT611904300234573201';

        yield 'already canonical passes through' => [$canonical, $canonical];
        yield 'lower-case is upper-cased' => ['at611904300234573201', $canonical];
        yield 'ASCII spaces are stripped' => ['AT61 1904 3002 3457 3201', $canonical];
        yield 'no-break spaces are stripped' => ["AT61\u{00A0}1904\u{00A0}3002\u{00A0}3457\u{00A0}3201", $canonical];
        yield 'narrow no-break spaces are stripped' => ["AT61\u{202F}1904300234573201", $canonical];
        yield 'the three separators mixed' => ["at61\u{00A0}1904 3002\u{202F}3457 3201", $canonical];
        yield 'empty stays empty' => ['', ''];
    }

    /**
     * The separators are ASCII-invisible, so a divergence here reads as "identical strings differ" in
     * a diff. Asserting the byte length pins the removal rather than the appearance.
     */
    public function testACanonicalIbanCarriesNoSeparatorBytesAtAll(): void
    {
        $canonical = Iban::canonicalize("AT61\u{00A0}1904 3002\u{202F}3457 3201");

        $this->assertSame(20, \strlen($canonical));
        $this->assertMatchesRegularExpression('/^[A-Z0-9]+$/', $canonical);
    }
}
