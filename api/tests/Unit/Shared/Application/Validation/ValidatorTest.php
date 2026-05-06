<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Application\Validation;

use Erpify\Shared\Application\Validation\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\GroupSequence;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[CoversClass(Validator::class)]
final class ValidatorTest extends TestCase
{
    public function testReturnsSilentlyWhenNoViolations(): void
    {
        $constraints = [new NotBlank(), new Uuid()];

        $innerValidator = $this->createMock(ValidatorInterface::class);
        $innerValidator
            ->expects($this->once())
            ->method('validate')
            ->with('7d4d4f4f-2c2c-4f4f-9c2c-1d1d1d1d1d1d', $constraints, null)
            ->willReturn(new ConstraintViolationList())
        ;

        (new Validator($innerValidator))->ensure('7d4d4f4f-2c2c-4f4f-9c2c-1d1d1d1d1d1d', $constraints);
    }

    public function testThrowsValidationFailedExceptionCarryingOriginalValueAndViolations(): void
    {
        $value = 'not-a-uuid';
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation('Invalid UUID.', null, [], $value, '', $value),
        ]);

        $innerValidator = $this->createStub(ValidatorInterface::class);
        $innerValidator
            ->method('validate')
            ->willReturn($constraintViolationList)
        ;

        try {
            (new Validator($innerValidator))->ensure($value, [new Uuid()]);
            $this->fail('Expected ValidationFailedException to be thrown.');
        } catch (ValidationFailedException $validationFailedException) {
            $this->assertSame($value, $validationFailedException->getValue());
            $this->assertSame($constraintViolationList, $validationFailedException->getViolations());
        }
    }

    public function testPassesGroupsThroughToInnerValidator(): void
    {
        $value = 'whatever';
        $constraints = [new NotBlank()];
        $groups = ['Default', 'Strict'];

        $innerValidator = $this->createMock(ValidatorInterface::class);
        $innerValidator
            ->expects($this->once())
            ->method('validate')
            ->with($value, $constraints, $groups)
            ->willReturn(new ConstraintViolationList())
        ;

        (new Validator($innerValidator))->ensure($value, $constraints, $groups);
    }

    public function testForwardsNullConstraintsAndNullGroupsByDefault(): void
    {
        $innerValidator = $this->createMock(ValidatorInterface::class);
        $innerValidator
            ->expects($this->once())
            ->method('validate')
            ->with('value', null, null)
            ->willReturn(new ConstraintViolationList())
        ;

        (new Validator($innerValidator))->ensure('value');
    }

    public function testAcceptsSingleConstraintInsteadOfArray(): void
    {
        $notBlank = new NotBlank();

        $innerValidator = $this->createMock(ValidatorInterface::class);
        $innerValidator
            ->expects($this->once())
            ->method('validate')
            ->with('value', $notBlank, null)
            ->willReturn(new ConstraintViolationList())
        ;

        (new Validator($innerValidator))->ensure('value', $notBlank);
    }

    public function testAcceptsSingleStringGroup(): void
    {
        $constraints = [new NotBlank()];

        $innerValidator = $this->createMock(ValidatorInterface::class);
        $innerValidator
            ->expects($this->once())
            ->method('validate')
            ->with('value', $constraints, 'Strict')
            ->willReturn(new ConstraintViolationList())
        ;

        (new Validator($innerValidator))->ensure('value', $constraints, 'Strict');
    }

    public function testAcceptsGroupSequenceAsGroups(): void
    {
        $constraints = [new NotBlank()];
        $groupSequence = new GroupSequence(['Default', 'Strict']);

        $innerValidator = $this->createMock(ValidatorInterface::class);
        $innerValidator
            ->expects($this->once())
            ->method('validate')
            ->with('value', $constraints, $groupSequence)
            ->willReturn(new ConstraintViolationList())
        ;

        (new Validator($innerValidator))->ensure('value', $constraints, $groupSequence);
    }
}
