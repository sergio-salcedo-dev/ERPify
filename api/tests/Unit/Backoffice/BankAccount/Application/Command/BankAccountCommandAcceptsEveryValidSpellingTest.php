<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application\Command;

use Erpify\Backoffice\BankAccount\Application\Command\CreateBankAccountCommand;
use Erpify\Backoffice\BankAccount\Application\Command\UpdateBankAccountCommand;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * A transport DTO may not be narrower than the constraint that already gates its field.
 *
 * `iban` and `bic` are both canonicalized on the way into the aggregate — separators stripped, cased up
 * — so the value the DTO measures is longer than the value the system stores and the invariant governs.
 * A width declared on the raw value therefore rejects spellings that `Assert\Iban` and `Assert\Bic`
 * accept: a Maltese IBAN grouped in fours is 38 code points against 31 canonical, and a Spanish BIC
 * grouped the same way is 13 against 11. Both would be refused over a limit the stored value never
 * approaches, and only a direct API client would see it — the PWA compacts before it measures.
 *
 * The assertion is deliberately not "accept these lengths": a number is what produces this defect, and
 * a second number would only move it. It asks the constraint gating the field what it accepts, then
 * requires the command to accept the same. The IBAN sample spans ISO 13616 from its 15-character floor
 * to the 33-character ceiling in circulation, crossing 29 — the first canonical length whose grouped
 * form passes 34.
 *
 * What a green does NOT prove: that the sample is exhaustive. It is a sample, and a country whose
 * spelling is refused for some reason other than length is invisible here.
 *
 * @internal
 */
#[CoversClass(CreateBankAccountCommand::class)]
#[CoversClass(UpdateBankAccountCommand::class)]
final class BankAccountCommandAcceptsEveryValidSpellingTest extends TestCase
{
    private const string BANK_ID = '01912e9c-0000-7000-8000-000000000001';

    private const string CREATE = 'create';

    private const string UPDATE = 'update';

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    #[Test]
    #[DataProvider('groupedIbans')]
    public function itAcceptsEveryIbanSpellingItsOwnIbanConstraintAccepts(string $kind, string $canonical): void
    {
        $grouped = $this->inGroupsOfFour($canonical);
        $command = $this->commandWith($kind, $grouped);

        $this->assertTheFieldConstraintAccepts($grouped, Assert\Iban::class, 'iban');

        $this->assertSame([], $this->violationsOn('iban', $command), \sprintf(
            'The %s refuses `%s` (%d code points) although its own %s accepts it. The aggregate strips '
            . 'the separators before it stores or measures anything, so the value this rejection is '
            . 'about is %d code points long and well inside the ISO 13616 ceiling the aggregate already '
            . 'asserts. Only a direct API client meets this: the PWA compacts before it measures.',
            $command::class,
            $grouped,
            \mb_strlen($grouped),
            Assert\Iban::class,
            \mb_strlen($canonical),
        ));
    }

    #[Test]
    #[DataProvider('provideItAcceptsEveryBicSpellingItsOwnBicConstraintAcceptsCases')]
    public function itAcceptsEveryBicSpellingItsOwnBicConstraintAccepts(
        string $kind,
        string $canonicalBic,
        string $iban,
    ): void {
        $grouped = $this->inGroupsOfFour($canonicalBic);
        $command = $this->commandWith($kind, $iban, $grouped);

        $this->assertTheFieldConstraintAccepts($grouped, Assert\Bic::class, 'bic');

        $this->assertSame([], $this->violationsOn('bic', $command), \sprintf(
            'The %s refuses `%s` (%d code points) although its own %s accepts it — that constraint '
            . 'strips the spaces before it validates, and so does the aggregate before it stores. The '
            . 'value the rejection is about is %d code points long.',
            $command::class,
            $grouped,
            \mb_strlen($grouped),
            Assert\Bic::class,
            \mb_strlen($canonicalBic),
        ));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideItAcceptsEveryBicSpellingItsOwnBicConstraintAcceptsCases(): iterable
    {
        $pairs = [
            // The DE row is a control: grouped to 9, it fits any width the field could carry. Only the
            // ES row, 11 canonical against 13 grouped, exercises the refusal this test exists for.
            'DE 8 — control' => ['DEUTDEFF', 'DE89370400440532013000'],
            'ES 11 — grouped to 13' => ['BSCHESMMXXX', 'ES9121000418450200051332'],
        ];

        foreach ($pairs as $label => [$bic, $iban]) {
            yield 'create · ' . $label => [self::CREATE, $bic, $iban];
            yield 'update · ' . $label => [self::UPDATE, $bic, $iban];
        }
    }

    /**
     * The other direction, and the reason a field carrying no width is not a field carrying no guard: a
     * value that is not an IBAN at all — here one padded past the ceiling — must still be refused, and
     * refused by the constraint that understands the field rather than by a length nobody can attribute
     * to the stored value.
     */
    #[Test]
    #[DataProvider('groupedIbans')]
    public function itStillRefusesAValueThatIsNotAnIbanAtAll(string $kind, string $canonical): void
    {
        $overlong = $canonical . \str_repeat('0', 40);
        $command = $this->commandWith($kind, $overlong);

        $this->assertNotSame([], $this->violationsOn('iban', $command), \sprintf(
            'The %s accepted `%s`, which is 40 characters past a valid IBAN. A field with no declared '
            . 'width still has a guard: %s is what refuses this.',
            $command::class,
            $overlong,
            Assert\Iban::class,
        ));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function groupedIbans(): iterable
    {
        $ibans = [
            'NO 15 — the ISO 13616 floor' => 'NO9386011117947',
            'ES 24 — the length the rest of the suite uses' => 'ES9121000418450200051332',
            'BR 29 — the first canonical length whose grouped form passes 34' => 'BR9700360305000010009795493P1',
            'MT 31 — 38 code points grouped' => 'MT84MALT011000012345MTLCAST001S',
            'RU 33 — the longest in circulation' => 'RU0304452522540817810538091310419',
        ];

        foreach ($ibans as $label => $iban) {
            yield 'create · ' . $label => [self::CREATE, $iban];
            yield 'update · ' . $label => [self::UPDATE, $iban];
        }
    }

    /**
     * The premise, asked of the constraint the command actually declares — read off the property by
     * reflection rather than rebuilt here. A hand-rolled copy would agree with itself forever: flip the
     * DTO's `Assert\Bic` to the strict mode the entity uses and every sample below, being upper-case,
     * would stay green while the equivalence this test claims to assert had quietly stopped holding.
     *
     * @param class-string<Constraint> $constraintClass
     */
    private function assertTheFieldConstraintAccepts(string $value, string $constraintClass, string $field): void
    {
        $constraint = $this->declaredConstraint($field, $constraintClass);

        $this->assertCount(0, $this->validator->validate($value, $constraint), \sprintf(
            'The premise of this test does not hold: `%s` is rejected by the `%s` the command declares '
            . 'on `%s`, so it is not a spelling that field ever admits and this case asserts nothing. '
            . 'Fix the sample.',
            $value,
            $constraintClass,
            $field,
        ));
    }

    /**
     * Both commands declare the same constraints on `iban` and `bic`; either is a faithful source, and
     * a divergence between them is itself worth a red.
     *
     * @param class-string<Constraint> $constraintClass
     */
    private function declaredConstraint(string $field, string $constraintClass): Constraint
    {
        $onCreate = $this->declaredOn(CreateBankAccountCommand::class, $field, $constraintClass);
        $onUpdate = $this->declaredOn(UpdateBankAccountCommand::class, $field, $constraintClass);

        // Compared as serialized state, not as objects: these are two separate instances by
        // construction, so any identity comparison is vacuously false and any equality one is a fixer
        // away from becoming it.
        $this->assertSame(\serialize($onCreate), \serialize($onUpdate), \sprintf(
            'The two commands declare different `%s` configurations on `%s`, so there is no single '
            . 'contract to hold either of them to.',
            $constraintClass,
            $field,
        ));

        return $onCreate;
    }

    /**
     * The constraints sit on constructor parameters, but they declare `TARGET_PROPERTY`, so they can
     * only be instantiated through the property the promotion creates.
     *
     * @param class-string<CreateBankAccountCommand|UpdateBankAccountCommand> $command
     * @param class-string<Constraint>                                        $constraintClass
     */
    private function declaredOn(string $command, string $field, string $constraintClass): Constraint
    {
        $reflection = new ReflectionClass($command);

        $this->assertTrue($reflection->hasProperty($field), \sprintf(
            '`%s` declares no promoted `%s` property.',
            $command,
            $field,
        ));

        // IS_INSTANCEOF, because a command may declare a module-specific subclass of the stock
        // constraint (BicMatchingIban does). Exact matching would report "declares 0" for a field the
        // command in fact gates, and the gate would be asserting nothing while looking green.
        $attributes = $reflection->getProperty($field)->getAttributes(
            $constraintClass,
            ReflectionAttribute::IS_INSTANCEOF,
        );

        $this->assertCount(1, $attributes, \sprintf(
            '`%s` must declare exactly one `%s` on `%s` for this equivalence to mean anything; it '
            . 'declares %d.',
            $command,
            $constraintClass,
            $field,
            \count($attributes),
        ));

        return $attributes[0]->newInstance();
    }

    private function commandWith(
        string $kind,
        string $iban,
        ?string $bic = null,
    ): CreateBankAccountCommand|UpdateBankAccountCommand {
        return match ($kind) {
            self::CREATE => new CreateBankAccountCommand(
                bankId: self::BANK_ID,
                holderName: 'Grouped Spelling',
                iban: $iban,
                bic: $bic,
            ),
            self::UPDATE => new UpdateBankAccountCommand(
                holderName: 'Grouped Spelling',
                iban: $iban,
                bic: $bic,
            ),
            default => throw new LogicException(\sprintf('No command is registered for `%s`.', $kind)),
        };
    }

    /**
     * @return list<string>
     */
    private function violationsOn(string $field, CreateBankAccountCommand|UpdateBankAccountCommand $command): array
    {
        $messages = [];

        foreach ($this->validator->validate($command) as $constraintViolationList) {
            if ($field === $constraintViolationList->getPropertyPath()) {
                $messages[] = (string) $constraintViolationList->getMessage();
            }
        }

        return $messages;
    }

    private function inGroupsOfFour(string $value): string
    {
        return \trim(\chunk_split($value, 4, ' '));
    }
}
