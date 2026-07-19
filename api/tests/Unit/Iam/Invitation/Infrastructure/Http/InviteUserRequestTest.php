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
 * Pins the boundary contract of the invitation payload: a well-formed alta passes, while the failure modes the
 * console can produce (blank/malformed email, no role, an unknown role, more roles than the vocabulary holds)
 * each raise a violation so `#[MapRequestPayload]` answers 422 before
 * {@see \Erpify\Iam\Invitation\Application\SendInvitation} runs. The list-shape check is exercised over HTTP in
 * `features/backoffice/identity/invitation_create.feature` instead: a string-keyed array cannot be handed to a
 * `list<string>` parameter here without defeating the very type promise the constraint exists to keep.
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

    #[Test]
    public function aSetLargerThanTheVocabularyIsRejected(): void
    {
        $request = new InviteUserRequest('newbie@erpify.test', \array_fill(0, 6, Role::VIEWER->value));

        $this->assertGreaterThan(0, $this->validator->validate($request)->count());
    }

    #[Test]
    public function theCeilingTracksTheRoleVocabulary(): void
    {
        $this->assertCount(InviteUserRequest::MAX_ROLES, InviteUserRequest::roleValues());
    }
}
