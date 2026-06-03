<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Validator;

use BackedEnum;
use Erpify\Shared\Infrastructure\Validator\EnumType;
use Erpify\Shared\Infrastructure\Validator\EnumTypeValidator;
use Erpify\Tests\Unit\Shared\Infrastructure\Validator\Fixtures\FixtureLabeledEnum;
use Erpify\Tests\Unit\Shared\Infrastructure\Validator\Fixtures\FixtureStringEnum;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @internal
 *
 * @extends ConstraintValidatorTestCase<EnumTypeValidator>
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class EnumTypeValidatorTest extends ConstraintValidatorTestCase
{
    private const string MESSAGE = 'The value you selected is not a valid choice.';

    #[DataProvider('provideAcceptsValidValuesCases')]
    public function testAcceptsValidValues(mixed $value, EnumType $constraint): void
    {
        $this->validator->validate($value, $constraint);

        $this->assertNoViolation();
    }

    /**
     * @return iterable<string, array{0: mixed, 1: EnumType}>
     */
    public static function provideAcceptsValidValuesCases(): iterable
    {
        yield 'enum instance' => [FixtureStringEnum::A, new EnumType(FixtureStringEnum::class)];
        yield 'null when allowNull' => [null, new EnumType(FixtureStringEnum::class, allowNull: true)];
        yield 'instance within subset' => [
            FixtureStringEnum::A,
            new EnumType(FixtureStringEnum::class, cases: [FixtureStringEnum::A, FixtureStringEnum::B]),
        ];
    }

    #[DataProvider('provideRejectsInvalidValuesCases')]
    public function testRejectsInvalidValues(mixed $value, EnumType $constraint, string $expectedChoices): void
    {
        $this->validator->validate($value, $constraint);

        $this->buildViolation(self::MESSAGE)
            ->setParameter('{{ choices }}', $expectedChoices)
            ->assertRaised()
        ;
    }

    /**
     * @return iterable<string, array{0: mixed, 1: EnumType, 2: string}>
     */
    public static function provideRejectsInvalidValuesCases(): iterable
    {
        yield 'null without allowNull' => [null, new EnumType(FixtureStringEnum::class), '"a", "b", "c"'];
        yield 'raw backing scalar' => ['a', new EnumType(FixtureStringEnum::class), '"a", "b", "c"'];
        yield 'instance of another enum' => [
            FixtureLabeledEnum::ONE,
            new EnumType(FixtureStringEnum::class),
            '"a", "b", "c"',
        ];
        yield 'valid case outside subset' => [
            FixtureStringEnum::C,
            new EnumType(FixtureStringEnum::class, cases: [FixtureStringEnum::A, FixtureStringEnum::B]),
            '"a", "b"',
        ];
        yield 'human-readable labels listed' => [
            'nope',
            new EnumType(FixtureLabeledEnum::class),
            '"one", "two", "three"',
        ];
        // FOUR has no label, so a human-readable subset falls back to its backing value.
        yield 'human-readable subset with value fallback' => [
            FixtureLabeledEnum::THREE,
            new EnumType(FixtureLabeledEnum::class, cases: [FixtureLabeledEnum::ONE, FixtureLabeledEnum::FOUR]),
            '"one", "4"',
        ];
    }

    public function testInvalidEnumClassRaisesConstraintDefinitionException(): void
    {
        /** @var class-string<BackedEnum> $notAnEnum -- deliberately wrong to exercise the guard */
        // @phpstan-ignore varTag.nativeType (intentionally wrong type to exercise the runtime guard)
        $notAnEnum = stdClass::class;

        $this->expectException(ConstraintDefinitionException::class);

        $this->validator->validate('x', new EnumType($notAnEnum));
    }

    public function testWrongConstraintTypeRaisesUnexpectedTypeException(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate('x', new NotBlank());
    }

    #[Override]
    protected function createValidator(): ConstraintValidatorInterface
    {
        return new EnumTypeValidator();
    }
}
