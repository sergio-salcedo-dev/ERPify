<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Backoffice\BankAccount\Domain\Iban;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

/**
 * The canonical form must absorb every separator the validator gating the field accepts. That is not a
 * stylistic agreement between two lists: a spelling the validator lets through and the canonical form
 * leaves intact is persisted as written, and `bank_account.iban` is `unique` — so one real IBAN becomes
 * two rows that no search finds.
 *
 * The two sides drifted apart once already, and not through anything written here: `symfony/validator`
 * 7.2 widened `Assert\Iban` to accept the no-break and narrow no-break spaces (its own CHANGELOG says
 * so), while this application went on stripping the ASCII space alone. The constraint floats on a minor
 * (`^8.1.1`) and bumps arrive automatically, so the same widening can happen again.
 *
 * This gate therefore does not restate the separator list — restating it is what made the first drift
 * invisible, and a test that repeats the implementation's own literals can only ever agree with it. It
 * **asks the validator**: every candidate below is put through `Assert\Iban` inside a grouped IBAN, and
 * whichever ones it accepts are then required to canonicalize away. A future release that admits a
 * fourth separator turns this red on the bump instead of in production.
 *
 * Nor does it read the vendor's source: the list lives as an inline literal inside a private method
 * body, so scraping it would be exactly as brittle as copying it. Behaviour is the only stable contract
 * a dependency offers.
 *
 * What a green does NOT prove: that the candidate alphabet below is exhaustive. It covers the Unicode
 * whitespace and invisible-format characters a pasted IBAN plausibly carries; a separator outside it
 * that a future release accepts is invisible here, and review is the only control on that direction.
 *
 * @internal
 */
#[CoversNothing]
final class IbanSeparatorContractGateTest extends TestCase
{
    private const string CANONICAL = 'AT611904300234573201';

    /**
     * Whitespace and invisible-format characters a copy-paste can carry into an IBAN field. Membership
     * here is a question, not a claim — the validator answers it.
     */
    private const array CANDIDATES = [
        'U+0009 tab' => "\t",
        'U+000A line feed' => "\n",
        'U+000D carriage return' => "\r",
        'U+0020 space' => ' ',
        'U+00A0 no-break space' => "\u{00A0}",
        'U+1680 ogham space mark' => "\u{1680}",
        'U+2000 en quad' => "\u{2000}",
        'U+2002 en space' => "\u{2002}",
        'U+2003 em space' => "\u{2003}",
        'U+2007 figure space' => "\u{2007}",
        'U+2009 thin space' => "\u{2009}",
        'U+200A hair space' => "\u{200A}",
        'U+200B zero width space' => "\u{200B}",
        'U+202F narrow no-break space' => "\u{202F}",
        'U+205F medium mathematical space' => "\u{205F}",
        'U+3000 ideographic space' => "\u{3000}",
        'U+FEFF byte order mark' => "\u{FEFF}",
    ];

    /**
     * The contract, in the only direction that can hurt: whatever reaches the aggregate has to leave the
     * canonicalizer as one spelling. The set is derived from the validator on every run, so it grows
     * when the dependency grows.
     */
    #[Test]
    public function everySeparatorTheValidatorAcceptsIsAbsorbedByTheCanonicalForm(): void
    {
        $accepted = $this->separatorsTheValidatorAccepts();

        $this->assertNotEmpty($accepted, \sprintf(
            'The probe found no separator that `%s` accepts at all, which cannot be right — the ASCII '
            . 'space alone has always been accepted. Something changed in how the constraint is built '
            . 'here, and this gate would otherwise pass over an empty set.',
            Assert\Iban::class,
        ));

        foreach ($accepted as $name => $separator) {
            $this->assertSame(self::CANONICAL, Iban::canonicalize($this->grouped($separator)), \sprintf(
                '`%s` accepts an IBAN grouped with %s, so that spelling reaches the aggregate — but the '
                . 'canonical form does not absorb it, so it would be persisted as written. The `unique` '
                . 'column then holds two spellings of one IBAN and no search finds either. Add it to the '
                . 'separators in %s.',
                Assert\Iban::class,
                $name,
                Iban::class,
            ));
        }
    }

    /**
     * The converse, which is what keeps the assertion above from being satisfied by absorbing
     * everything: a canonicalizer wider than its validator would turn a rejected input into a stored
     * value. Each character the validator refuses must still reach the canonicalizer intact.
     */
    #[Test]
    public function theCanonicalFormAbsorbsNothingTheValidatorRefuses(): void
    {
        $accepted = $this->separatorsTheValidatorAccepts();
        $refused = \array_diff_key(self::CANDIDATES, $accepted);

        $this->assertNotEmpty($refused, 'The probe found no refused candidate, so this direction checks nothing.');

        foreach ($refused as $name => $separator) {
            $this->assertNotSame(self::CANONICAL, Iban::canonicalize($this->grouped($separator)), \sprintf(
                '`%s` refuses an IBAN grouped with %s, so it never reaches the aggregate — but the '
                . 'canonical form absorbs it anyway. A canonicalizer wider than the validator gating the '
                . 'field turns an input the edge rejects into a stored value the moment any other caller '
                . 'appears.',
                Assert\Iban::class,
                $name,
            ));
        }
    }

    /**
     * @return array<string, string>
     */
    private function separatorsTheValidatorAccepts(): array
    {
        $validator = Validation::createValidator();
        $accepted = [];

        foreach (self::CANDIDATES as $name => $separator) {
            if (0 === \count($validator->validate($this->grouped($separator), new Assert\Iban()))) {
                $accepted[$name] = $separator;
            }
        }

        return $accepted;
    }

    private function grouped(string $separator): string
    {
        return \implode($separator, ['AT61', '1904', '3002', '3457', '3201']);
    }
}
