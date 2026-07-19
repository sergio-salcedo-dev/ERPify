<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Infrastructure\Http\ChangeUserRolesRequest;
use Erpify\Shared\Access\Domain\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Pins the boundary contract of the role-assignment payload: a well-formed set passes, an empty set and an
 * unknown member each raise a violation so `#[MapRequestPayload]` answers 422 before
 * {@see \Erpify\Iam\Identity\Application\ChangeUserRoles} runs, and a duplicated member does NOT — the
 * aggregate owns the duplicate-free invariant, so collapsing beats rejecting.
 *
 * @internal
 */
#[CoversClass(ChangeUserRolesRequest::class)]
final class ChangeUserRolesRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    #[Test]
    public function roleValuesEnumeratesEveryRoleAsItsWireString(): void
    {
        $values = ChangeUserRolesRequest::roleValues();

        $this->assertCount(\count(Role::cases()), $values);

        foreach (Role::cases() as $role) {
            $this->assertContains($role->value, $values);
        }
    }

    #[Test]
    public function aWellFormedSetPassesValidation(): void
    {
        $request = new ChangeUserRolesRequest([Role::EDITOR->value, Role::AUDIT_READER->value]);

        $this->assertCount(0, $this->validator->validate($request));
    }

    #[Test]
    public function anEmptySetIsRejected(): void
    {
        $request = new ChangeUserRolesRequest([]);

        $this->assertGreaterThan(0, $this->validator->validate($request)->count());
    }

    #[Test]
    public function anUnknownRoleIsRejected(): void
    {
        $request = new ChangeUserRolesRequest(['ROOT']);

        $this->assertGreaterThan(0, $this->validator->validate($request)->count());
    }

    #[Test]
    public function aDuplicatedRoleIsAcceptedAndLeftForTheAggregateToCollapse(): void
    {
        $request = new ChangeUserRolesRequest([Role::MANAGER->value, Role::MANAGER->value]);

        $this->assertCount(0, $this->validator->validate($request));
    }
}
