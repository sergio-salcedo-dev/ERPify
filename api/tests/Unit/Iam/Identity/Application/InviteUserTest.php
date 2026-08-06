<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\InviteUser;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Validation\Application\Validator;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[CoversClass(InviteUser::class)]
final class InviteUserTest extends TestCase
{
    #[Test]
    public function itProvisionsAnInvitedCredentiallessIdentityWithRoles(): void
    {
        $users = new InMemoryUserRepository();

        $user = (new InviteUser($users, $this->passingValidator(), new RecordingAuditLogger()))
            ->invite('Alice@Erpify.Test', Role::EDITOR)
        ;

        $this->assertSame([$user], $users->saved);
        $this->assertSame('alice@erpify.test', $user->email());
        $this->assertSame(IdentityStatus::INVITED, $user->status());
        $this->assertNotInstanceOf(\Erpify\Iam\Identity\Domain\HashedPassword::class, $user->passwordHash());
        $this->assertSame([Role::EDITOR], $user->roles());
    }

    #[Test]
    public function itAllowsARolelessInvite(): void
    {
        $users = new InMemoryUserRepository();

        $user = (new InviteUser($users, $this->passingValidator(), new RecordingAuditLogger()))
            ->invite('bob@erpify.test')
        ;

        $this->assertSame([], $user->roles());
        $this->assertSame(IdentityStatus::INVITED, $user->status());
    }

    #[Test]
    public function itRecordsTheRolesAnInvitationGrantsAgainstTheInvitedSubject(): void
    {
        // Without this row the console's two delegation paths disagree: the permission names the act on both,
        // but only the role change leaves a trail — so minting a second administrator off the record would be a
        // matter of inviting one instead of promoting one.
        $audit = new RecordingAuditLogger();

        $user = (new InviteUser(new InMemoryUserRepository(), $this->passingValidator(), $audit))
            ->invite('carol@erpify.test', Role::ADMIN, Role::EDITOR, Role::ADMIN)
        ;

        $this->assertCount(1, $audit->records);
        $record = $audit->records[0];
        $resource = $record['resource'];
        $this->assertInstanceOf(
            AuditResource::class,
            $resource,
            'the grant must name the invited subject, not sit resource-less',
        );

        $this->assertSame('USER_ROLES_GRANTED', $record['action']);
        $this->assertSame(AuditLevel::SECURITY, $record['level']);
        $this->assertSame(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $resource->type);
        $this->assertSame($user->getId(), $resource->id);
        // Frozen whole, so a later key rename or an extra field fails here rather than silently changing the
        // contract every consumer of the trail reads. Canonical: sorted, duplicate-free.
        $this->assertSame(
            ['previous_roles' => [], 'new_roles' => ['ADMIN', 'EDITOR']],
            $record['metadata'],
        );
    }

    #[Test]
    public function itPutsNoCredentialOrEmailInTheGrantRecord(): void
    {
        $audit = new RecordingAuditLogger();

        (new InviteUser(new InMemoryUserRepository(), $this->passingValidator(), $audit))
            ->invite('dora@erpify.test', Role::VIEWER)
        ;

        $this->assertCount(1, $audit->records);
        $this->assertStringNotContainsStringIgnoringCase(
            'dora@erpify.test',
            \json_encode($audit->records[0]['metadata'], JSON_THROW_ON_ERROR),
        );
    }

    private function passingValidator(): Validator
    {
        $inner = $this->createStub(ValidatorInterface::class);
        $inner->method('validate')->willReturn(new ConstraintViolationList());

        return new Validator($inner);
    }
}
