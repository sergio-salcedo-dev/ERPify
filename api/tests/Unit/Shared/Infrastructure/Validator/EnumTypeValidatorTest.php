<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Validator;

use Erpify\Shared\Infrastructure\Validator\EnumType;
use Erpify\Shared\Infrastructure\Validator\EnumTypeValidator;
use Erpify\Tests\Unit\Shared\Infrastructure\Validator\Fixtures\FixtureLabeledEnum;
use Erpify\Tests\Unit\Shared\Infrastructure\Validator\Fixtures\FixtureStringEnum;
use Override;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @internal
 *
 * @extends ConstraintValidatorTestCase<EnumTypeValidator>
 */
final class EnumTypeValidatorTest extends ConstraintValidatorTestCase
{
    private const string MESSAGE = 'The value you selected is not a valid choice.';

    public function testValidEnumInstancePasses(): void
    {
        $this->validator->validate(FixtureStringEnum::A, new EnumType(FixtureStringEnum::class));

        $this->assertNoViolation();
    }

    public function testNullWithAllowNullPasses(): void
    {
        $this->validator->validate(null, new EnumType(FixtureStringEnum::class, allowNull: true));

        $this->assertNoViolation();
    }

    public function testNullWithoutAllowNullRaisesViolation(): void
    {
        $this->validator->validate(null, new EnumType(FixtureStringEnum::class));

        $this->buildViolation(self::MESSAGE)
            ->setParameter('{{ choices }}', '"a", "b", "c"')
            ->assertRaised();
    }

    public function testRawScalarRaisesViolation(): void
    {
        $this->validator->validate('a', new EnumType(FixtureStringEnum::class));

        $this->buildViolation(self::MESSAGE)
            ->setParameter('{{ choices }}', '"a", "b", "c"')
            ->assertRaised();
    }

    public function testDifferentEnumInstanceRaisesViolation(): void
    {
        $this->validator->validate(FixtureLabeledEnum::ONE, new EnumType(FixtureStringEnum::class));

        $this->buildViolation(self::MESSAGE)
            ->setParameter('{{ choices }}', '"a", "b", "c"')
            ->assertRaised();
    }

    public function testValueInSubsetPasses(): void
    {
        $this->validator->validate(
            FixtureStringEnum::A,
            new EnumType(FixtureStringEnum::class, cases: [FixtureStringEnum::A, FixtureStringEnum::B]),
        );

        $this->assertNoViolation();
    }

    public function testValidEnumOutsideSubsetRaisesViolation(): void
    {
        $this->validator->validate(
            FixtureStringEnum::C,
            new EnumType(FixtureStringEnum::class, cases: [FixtureStringEnum::A, FixtureStringEnum::B]),
        );

        $this->buildViolation(self::MESSAGE)
            ->setParameter('{{ choices }}', '"a", "b"')
            ->assertRaised();
    }

    public function testChoicesUseLabelsForHumanReadableEnum(): void
    {
        $this->validator->validate('nope', new EnumType(FixtureLabeledEnum::class));

        $this->buildViolation(self::MESSAGE)
            ->setParameter('{{ choices }}', '"one", "two", "three"')
            ->assertRaised();
    }

    public function testInvalidEnumClassRaisesConstraintDefinitionException(): void
    {
        /** @var class-string<\BackedEnum> $notAnEnum -- deliberately wrong to exercise the guard */
        // @phpstan-ignore varTag.nativeType (intentionally wrong type to exercise the runtime guard)
        $notAnEnum = \stdClass::class;

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
