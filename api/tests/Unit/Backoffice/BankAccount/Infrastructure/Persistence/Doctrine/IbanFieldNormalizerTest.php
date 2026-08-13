<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine\IbanFieldNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(IbanFieldNormalizer::class)]
final class IbanFieldNormalizerTest extends TestCase
{
    /**
     * The search value must be reduced to the same compact upper-case form the IBAN is stored in
     * (every separator the validator accepts stripped, upper-cased), so a human-grouped or lower-case fragment still matches the
     * column under eq/in/contains.
     */
    #[DataProvider('provideItReducesTheSearchValueToTheStoredCompactUpperFormCases')]
    public function testItReducesTheSearchValueToTheStoredCompactUpperForm(string $input, string $expected): void
    {
        $this->assertSame($expected, (new IbanFieldNormalizer())->normalize($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideItReducesTheSearchValueToTheStoredCompactUpperFormCases(): iterable
    {
        yield 'already canonical passes through' => ['DE89370400440532013000', 'DE89370400440532013000'];
        yield 'human-grouped spaces are stripped' => ['DE89 3704 0044 0532 0130 00', 'DE89370400440532013000'];
        yield 'lower-case is upper-cased' => ['de89370400440532013000', 'DE89370400440532013000'];
        yield 'mixed case and a grouped fragment' => ['de89 3704', 'DE893704'];
        yield 'no-break spaces are stripped' => [
            "DE89\u{00A0}3704\u{00A0}0044\u{00A0}0532\u{00A0}0130\u{00A0}00",
            'DE89370400440532013000',
        ];
        yield 'narrow no-break spaces are stripped' => [
            "DE89\u{202F}370400440532013000",
            'DE89370400440532013000',
        ];
        yield 'empty value stays empty' => ['', ''];
    }
}
