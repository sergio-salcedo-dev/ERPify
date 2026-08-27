<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application\Query;

use Erpify\Backoffice\BankAccount\Application\Query\LookupBankAccountByIbanQuery;
use Erpify\Backoffice\BankAccount\Domain\Iban;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(LookupBankAccountByIbanQuery::class)]
final class LookupBankAccountByIbanQueryTest extends TestCase
{
    public function testDefaultsToAnEmptyIban(): void
    {
        $query = new LookupBankAccountByIbanQuery();

        $this->assertSame('', $query->iban);
    }

    /**
     * The same rule the write side and the search normalizer apply — see {@see Iban}.
     */
    public function testCanonicalIbanUpperCasesAndStripsSeparators(): void
    {
        $query = new LookupBankAccountByIbanQuery('de89 3704 0044 0532 0130 00');

        $this->assertSame('DE89370400440532013000', $query->canonicalIban());
    }
}
