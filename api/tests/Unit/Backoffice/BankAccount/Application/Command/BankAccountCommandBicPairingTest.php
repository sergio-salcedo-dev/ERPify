<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application\Command;

use Erpify\Backoffice\BankAccount\Application\Command\CreateBankAccountCommand;
use Erpify\Backoffice\BankAccount\Application\Command\UpdateBankAccountCommand;
use Erpify\Backoffice\BankAccount\Application\Validation\BicMatchingIban;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The BIC↔IBAN country check must survive the spellings the IBAN field accepts.
 *
 * `Assert\Bic` reads the paired IBAN through a property path and takes its country from
 * `substr($iban, 0, 2)`. Read from the value as submitted, a spelling that leads with a separator —
 * the ordinary shape of a copy-paste out of a statement — starts with a character `ctype_alpha()`
 * refuses, and the constraint skips the comparison entirely rather than failing it: the edge passes a
 * pair it exists to reject and the aggregate is the first thing to notice, one layer later. The
 * commands therefore point the path at the CANONICAL form, and this pins that they do.
 *
 * Each case asserts the violation's CODE, not merely that `bic` failed: a malformed BIC also fails on
 * `bic`, so a path-only assertion would stay green over a sample that never reaches the country
 * comparison at all.
 *
 * What a green does NOT prove: that every separator produces the skip. Of the spellings below only
 * the two leading-separator ones can falsify the property path — the rest already start with two
 * letters — and they are kept as controls rather than as coverage.
 *
 * @internal
 */
#[CoversClass(BicMatchingIban::class)]
#[CoversClass(CreateBankAccountCommand::class)]
#[CoversClass(UpdateBankAccountCommand::class)]
final class BankAccountCommandBicPairingTest extends TestCase
{
    private const string BANK_ID = '01912e9c-0000-7000-8000-000000000001';

    /** A Spanish IBAN against a German BIC: the pair can never be legitimate. */
    private const string SPANISH_IBAN = 'ES9121000418450200051332';

    private const string GERMAN_BIC = 'DEUTDEFF';

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    #[Test]
    #[DataProvider('ibanSpellings')]
    public function theCreateCommandRefusesAMismatchedPairInEverySpelling(string $iban): void
    {
        $this->assertBicIsRefused(new CreateBankAccountCommand(
            bankId: self::BANK_ID,
            holderName: 'Mismatch Holder',
            iban: $iban,
            bic: self::GERMAN_BIC,
        ), $iban);
    }

    #[Test]
    #[DataProvider('ibanSpellings')]
    public function theUpdateCommandRefusesAMismatchedPairInEverySpelling(string $iban): void
    {
        $this->assertBicIsRefused(new UpdateBankAccountCommand(
            holderName: 'Mismatch Holder',
            iban: $iban,
            bic: self::GERMAN_BIC,
        ), $iban);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ibanSpellings(): iterable
    {
        yield 'canonical' => [self::SPANISH_IBAN];
        yield 'leading separator' => [' ' . self::SPANISH_IBAN];
        yield 'grouped' => ['ES91 2100 0418 4502 0005 1332'];
        yield 'grouped behind a separator' => [' ES91 2100 0418 4502 0005 1332'];
        yield 'lower-cased' => [\strtolower(self::SPANISH_IBAN)];
    }

    private function assertBicIsRefused(
        CreateBankAccountCommand|UpdateBankAccountCommand $command,
        string $iban,
    ): void {
        $codes = [];

        foreach ($this->validator->validate($command) as $constraintViolationList) {
            if ('bic' === $constraintViolationList->getPropertyPath()) {
                $codes[] = $constraintViolationList->getCode();
            }
        }

        $this->assertContains(Assert\Bic::INVALID_IBAN_COUNTRY_CODE_ERROR, $codes, \sprintf(
            'The %s did not refuse a German BIC against the Spanish IBAN `%s` on country grounds. The '
            . 'comparison takes the country from the first two characters of the IBAN it is pointed '
            . 'at, so a spelling that does not begin with two letters skips it silently — and the edge '
            . 'passes a pair it exists to reject.',
            $command::class,
            $iban,
        ));
    }
}
