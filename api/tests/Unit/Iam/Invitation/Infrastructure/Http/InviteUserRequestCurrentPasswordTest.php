<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Infrastructure\Http;

use Erpify\Iam\Invitation\Infrastructure\Http\InviteUserRequest;
use Erpify\Shared\Access\Domain\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The one conditional member of {@see InviteUserRequest}: `currentPassword` is owed exactly when the requested
 * roles carry `ADMIN`, and never otherwise. Apart from the payload's shape tests because it is a rule about
 * two members at once, which no per-property constraint can state.
 *
 * @internal
 */
#[CoversClass(InviteUserRequest::class)]
final class InviteUserRequestCurrentPasswordTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    #[Test]
    public function anAdministratorInvitationWithoutTheCurrentPasswordIsRejectedOnThatField(): void
    {
        $violations = $this->validator->validate(
            new InviteUserRequest('newbie@erpify.test', [Role::VIEWER->value, Role::ADMIN->value]),
        );

        $this->assertCount(1, $violations);
        $this->assertSame('currentPassword', $violations->get(0)->getPropertyPath());
    }

    #[Test]
    public function anAdministratorInvitationWithTheCurrentPasswordPassesValidation(): void
    {
        $request = new InviteUserRequest('newbie@erpify.test', [Role::ADMIN->value], 'the-current-password');

        $this->assertCount(0, $this->validator->validate($request));
    }

    #[Test]
    public function aNonAdministratorInvitationNeedsNoPassword(): void
    {
        $request = new InviteUserRequest('newbie@erpify.test', [Role::MANAGER->value]);

        $this->assertCount(0, $this->validator->validate($request));
    }
}
