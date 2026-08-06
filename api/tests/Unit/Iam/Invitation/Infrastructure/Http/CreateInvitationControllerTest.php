<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Infrastructure\Http;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\InviteUser;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Invitation\Application\SendInvitation;
use Erpify\Iam\Invitation\Application\SendInvitationEmailBestEffort;
use Erpify\Iam\Invitation\Infrastructure\Http\CreateInvitationController;
use Erpify\Iam\Invitation\Infrastructure\Http\InviteUserRequest;
use Erpify\Organization\Membership\Application\GrantMembership;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Validation\Application\Validator;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Invitation\Application\FixedClock;
use Erpify\Tests\Unit\Iam\Invitation\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Invitation\Application\InMemoryInvitationRepository;
use Erpify\Tests\Unit\Iam\Invitation\Application\RecordingEventBus;
use Erpify\Tests\Unit\Iam\Invitation\Application\SpyInvitationEmailSender;
use Erpify\Tests\Unit\Organization\Membership\Application\InMemoryMembershipRepository;
use Erpify\Tests\Unit\Organization\Organization\Application\InMemoryOrganizationRepository;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Drives the thin invitation controller over the real {@see SendInvitation} funnel (Identity + Organization +
 * Invitation use cases on in-memory repositories) so the wire path proves out: the validated request maps to
 * the {@see Role} enum, the member is invited, and the answer is a bodyless 201 (the accept token stays a
 * secret, the identity stays out of the response).
 *
 * The role-delegation guard is pinned on all three of its axes, because each is a distinct way it could
 * regress into something weaker than it looks: it refuses BEFORE the funnel writes anything, it is asked only
 * when the payload actually carries `ADMIN`, and it asks with no subject — an authorization decision taken on
 * the row or the payload would be the ABAC drift the voter's own tripwires forbid.
 *
 * The coupling is inherent to weaving that real three-context funnel with concrete collaborators rather than
 * mocking the use case away.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(CreateInvitationController::class)]
final class CreateInvitationControllerTest extends TestCase
{
    private const string ORG_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b70';

    private const string NOW = '2026-07-17T10:00:00+00:00';

    private const string GRANT_ADMIN = 'users.grantAdmin';

    #[Test]
    public function itInvitesTheMemberAndAnswersABodylessCreated(): void
    {
        $users = new InMemoryUserRepository();
        $invitations = new InMemoryInvitationRepository();
        $authorization = new RecordingAuthorizationChecker(granted: false);
        $controller = new CreateInvitationController(
            $this->sendInvitation($users, $invitations),
            $authorization,
        );

        $response = $controller(
            new InviteUserRequest('Newbie@Erpify.Test', [Role::MANAGER->value, Role::VIEWER->value]),
        );

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('', (string) $response->getContent());

        $this->assertCount(1, $users->saved);
        $this->assertSame(IdentityStatus::INVITED, $users->saved[0]->status());
        $this->assertCount(1, $invitations->saved);

        // The checker denies everything here, and the invite still succeeds: the extra permission is a
        // property of the payload, never of the route, so a non-admin set must not be made to depend on it.
        $this->assertSame([], $authorization->askedAttributes);
    }

    #[Test]
    public function itRefusesToInviteAnAdministratorWithoutTheDelegationPermission(): void
    {
        $users = new InMemoryUserRepository();
        $invitations = new InMemoryInvitationRepository();
        $controller = new CreateInvitationController(
            $this->sendInvitation($users, $invitations),
            new RecordingAuthorizationChecker(granted: false),
        );

        try {
            $controller(new InviteUserRequest('rogue@erpify.test', [Role::ADMIN->value]));
            $this->fail('Expected AccessDeniedException.');
        } catch (AccessDeniedException $accessDeniedException) {
            // The message travels as the RFC 9457 title, so it is part of the wire contract: it names the
            // refused act and nothing about permissions, policies or the caller's own roles.
            $this->assertSame('You may not grant the ADMIN role.', $accessDeniedException->getMessage());
        }

        // Refused before the funnel, so no identity, no membership and no invitation exist to clean up.
        $this->assertSame([], $users->saved);
        $this->assertSame([], $invitations->saved);
    }

    #[Test]
    public function itAsksForTheDelegationPermissionByNameAndWithNoSubject(): void
    {
        $authorization = new RecordingAuthorizationChecker(granted: true);
        $controller = new CreateInvitationController(
            $this->sendInvitation(new InMemoryUserRepository(), new InMemoryInvitationRepository()),
            $authorization,
        );

        $controller(new InviteUserRequest('second-admin@erpify.test', [Role::ADMIN->value]));

        $this->assertSame([self::GRANT_ADMIN], $authorization->askedAttributes);
        // Handing the requested roles over as the voter's subject would turn an RBAC decision into an ABAC
        // one; the permission is the whole question, and the payload only decides whether it is asked.
        $this->assertSame([null], $authorization->askedSubjects);
    }

    private function sendInvitation(
        InMemoryUserRepository $users,
        InMemoryInvitationRepository $invitations,
    ): SendInvitation {
        $organizations = new InMemoryOrganizationRepository();
        $organizations->save(Organization::provision(self::ORG_ID, 'ACME'));

        return new SendInvitation(
            new InviteUser($users, $this->passingValidator(), new RecordingAuditLogger()),
            new GrantMembership(new InMemoryMembershipRepository(), $organizations),
            $invitations,
            new SendInvitationEmailBestEffort(new SpyInvitationEmailSender(), new NullLogger()),
            new RecordingEventBus(),
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable(self::NOW)),
        );
    }

    private function passingValidator(): Validator
    {
        $inner = $this->createStub(ValidatorInterface::class);
        $inner->method('validate')->willReturn(new ConstraintViolationList());

        return new Validator($inner);
    }
}
