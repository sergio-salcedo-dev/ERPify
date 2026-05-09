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

    public function testRebindsEmptyPropertyPathOntoSuppliedName(): void
    {
        $value = 'not-a-uuid';
        $original = new ConstraintViolationList([
            new ConstraintViolation('Invalid UUID.', null, [], $value, '', $value, null, 'uuid-code'),
        ]);

        $innerValidator = $this->createStub(ValidatorInterface::class);
        $innerValidator
            ->method('validate')
            ->willReturn($original)
        ;

        try {
            (new Validator($innerValidator))->ensure($value, [new Uuid()], propertyPath: 'id');
            $this->fail('Expected ValidationFailedException to be thrown.');
        } catch (ValidationFailedException $validationFailedException) {
            $rebound = $validationFailedException->getViolations();
            $this->assertCount(1, $rebound);
            $this->assertSame('id', $rebound->get(0)->getPropertyPath());
            $this->assertSame('Invalid UUID.', $rebound->get(0)->getMessage());
            $this->assertSame('uuid-code', $rebound->get(0)->getCode());
        }
    }

    public function testPreservesViolationsThatAlreadyCarryAPropertyPath(): void
    {
        $original = new ConstraintViolationList([
            new ConstraintViolation('blank', null, [], null, 'name', '', null, 'blank-code'),
            new ConstraintViolation('bad', null, [], null, '', 'x', null, 'bad-code'),
        ]);

        $innerValidator = $this->createStub(ValidatorInterface::class);
        $innerValidator
            ->method('validate')
            ->willReturn($original)
        ;

        try {
            (new Validator($innerValidator))->ensure('whatever', null, null, 'fallback');
            $this->fail('Expected ValidationFailedException to be thrown.');
        } catch (ValidationFailedException $validationFailedException) {
            $rebound = $validationFailedException->getViolations();
            $this->assertSame('name', $rebound->get(0)->getPropertyPath());
            $this->assertSame('fallback', $rebound->get(1)->getPropertyPath());
        }
    }
}
