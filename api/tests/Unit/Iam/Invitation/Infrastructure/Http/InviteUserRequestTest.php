<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Infrastructure\Http;

use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Invitation\Infrastructure\Http\InviteUserRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Pins the boundary contract of the invitation payload: a well-formed alta passes, while the failure modes the
 * console can produce (blank/malformed email, no role, an unknown role) each raise a violation so
 * `#[MapRequestPayload]` answers 422 before {@see \Erpify\Iam\Invitation\Application\SendInvitation} runs.
 *
 * @internal
 */
#[CoversClass(InviteUserRequest::class)]
final class InviteUserRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    #[Test]
    public function roleValuesEnumeratesEveryRoleAsItsWireString(): void
    {
        $values = InviteUserRequest::roleValues();

        $this->assertCount(\count(Role::cases()), $values);

        foreach (Role::cases() as $role) {
            $this->assertContains($role->value, $values);
        }
    }

    #[Test]
    public function aWellFormedInvitationPassesValidation(): void
    {
        $request = new InviteUserRequest('newbie@erpify.test', [Role::EDITOR->value, Role::VIEWER->value]);

        $this->assertCount(0, $this->validator->validate($request));
    }

    #[Test]
    public function aBlankEmailIsRejected(): void
    {
        $request = new InviteUserRequest('', [Role::VIEWER->value]);

        $this->assertGreaterThan(0, $this->validator->validate($request)->count());
    }

    #[Test]
    public function aMalformedEmailIsRejected(): void
    {
        $request = new InviteUserRequest('not-an-email', [Role::VIEWER->value]);

        $this->assertGreaterThan(0, $this->validator->validate($request)->count());
    }

    #[Test]
    public function anEmptyRoleListIsRejected(): void
    {
        $request = new InviteUserRequest('newbie@erpify.test', []);

        $this->assertGreaterThan(0, $this->validator->validate($request)->count());
    }

    #[Test]
    public function anUnknownRoleIsRejected(): void
    {
        $request = new InviteUserRequest('newbie@erpify.test', ['ROOT']);

        $this->assertGreaterThan(0, $this->validator->validate($request)->count());
    }
}
