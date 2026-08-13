<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Which spellings of the same account the aggregate treats as one. The canonical form has to absorb
 * every separator the validators gating those fields already absorb, or a value accepted at the edge
 * is persisted in a spelling the `unique` column cannot match — two rows for one real IBAN, and a
 * search for either finding neither.
 *
 * @internal
 */
#[CoversClass(BankAccount::class)]
final class BankAccountCanonicalEquivalenceTest extends TestCase
{
    use StoredBankAccountFixture;

    /**
     * The separator a copy-paste out of a statement carries. `Assert\Iban` accepts it, so it reaches
     * the aggregate; if the canonical form did not absorb it the write would look like a change,
     * persist a spelling the unique column cannot match, and leave two rows for one real IBAN.
     */
    public function testUpdateComparesTheCanonicalIbanSoNonAsciiSeparatorsAreANoOp(): void
    {
        $account = $this->storedAccount();

        $account->update(self::HOLDER_NAME, "DE89\u{00A0}3704\u{202F}0044 0532 0130 00", null, null, Currency::EUR);

        $this->assertSame(self::IBAN, $account->getIban());
        $this->assertNoOp($account);
    }

    /**
     * `Assert\Bic` strips the space before it checks length and alphabet, so any grouped spelling of a
     * valid BIC reaches the aggregate — the 8-character form here and the 11-character one alike. What
     * this case pins is the aggregate's own half: two spellings of one BIC must compare equal after
     * canonicalization, or a re-key of the same value would read as a change and write.
     */
    public function testUpdateComparesTheCanonicalBicSoSpacingOnlyDifferencesAreANoOp(): void
    {
        $account = $this->storedAccount(bic: 'DEUTDEFF');

        $account->update(self::HOLDER_NAME, self::IBAN, 'DEUT DEFF', null, Currency::EUR);

        $this->assertSame('DEUTDEFF', $account->getBic());
        $this->assertNoOp($account);
    }

    /**
     * Deliberately wider than `Assert\Bic`, which refuses a whitespace-only BIC rather than reading it
     * as absent — so this is a domain choice, not a mirror of the edge. It is defensible in the one
     * direction that matters: the widening can only ever produce absence, never a stored value the
     * validator would have refused. No HTTP caller reaches it (the command's own `Assert\Bic` rejects
     * the input first); it exists for the direct constructors the command docblock declares supported.
     */
    public function testUpdateTreatsABicOfNothingButSpacesAsTheStoredAbsentOne(): void
    {
        $account = $this->storedAccount();

        $account->update(self::HOLDER_NAME, self::IBAN, '   ', null, Currency::EUR);

        $this->assertNull($account->getBic());
        $this->assertNoOp($account);
    }
}
