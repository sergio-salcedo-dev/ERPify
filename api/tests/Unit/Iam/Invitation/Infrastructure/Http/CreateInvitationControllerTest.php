<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Infrastructure\Http;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\InviteUser;
use Erpify\Iam\Identity\Application\ProveActorPassword;
use Erpify\Iam\Identity\Application\ProveCurrentPassword;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Security\CurrentPasswordProofThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Iam\Invitation\Application\SendInvitation;
use Erpify\Iam\Invitation\Application\SendInvitationEmailBestEffort;
use Erpify\Iam\Invitation\Infrastructure\Http\CreateInvitationController;
use Erpify\Iam\Invitation\Infrastructure\Http\InviteUserRequest;
use Erpify\Organization\Membership\Application\GrantMembership;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Shared\Validation\Application\Validator;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Drives the thin invitation controller over the real {@see SendInvitation} funnel (Identity + Organization +
 * Invitation use cases on in-memory repositories) so the wire path proves out: the validated request maps to
 * the {@see Role} enum, the member is invited, and the answer is a bodyless 201 (the accept token stays a
 * secret, the identity stays out of the response).
 *
 * Minting an administrator also costs the CALLER's current password, and only then: the proof is asked when
 * the payload carries `ADMIN`, spends the shared per-identity budget before the funnel writes anything, and a
 * non-administrator invite neither needs a password nor touches the budget.
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

    private const string PASSWORD = 'the-current-password';

    protected function setUp(): void
    {
        parent::setUp();
        SystemClock::set(FixedClock::at(self::NOW));
    }

    #[Test]
    public function itInvitesTheMemberAndAnswersABodylessCreated(): void
    {
        $users = $this->usersWithTheCaller();
        $invitations = new InMemoryInvitationRepository();
        $authorization = new RecordingAuthorizationChecker(granted: false);
        $controller = $this->controller($users, $invitations, $authorization);

        $response = $controller(
            $this->caller(),
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
        $users = $this->usersWithTheCaller();
        $invitations = new InMemoryInvitationRepository();
        $controller = $this->controller($users, $invitations, new RecordingAuthorizationChecker(granted: false));

        try {
            $controller(
                $this->caller(),
                new InviteUserRequest('rogue@erpify.test', [Role::ADMIN->value], self::PASSWORD),
            );
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
        $controller = $this->controller(
            $this->usersWithTheCaller(),
            new InMemoryInvitationRepository(),
            $authorization,
        );

        $controller(
            $this->caller(),
            new InviteUserRequest('second-admin@erpify.test', [Role::ADMIN->value], self::PASSWORD),
        );

        $this->assertSame([self::GRANT_ADMIN], $authorization->askedAttributes);
        // Handing the requested roles over as the voter's subject would turn an RBAC decision into an ABAC
        // one; the permission is the whole question, and the payload only decides whether it is asked.
        $this->assertSame([null], $authorization->askedSubjects);
    }

    #[Test]
    public function invitingAnAdministratorWithTheRightPasswordSucceeds(): void
    {
        $users = $this->usersWithTheCaller();
        $invitations = new InMemoryInvitationRepository();
        $controller = $this->controller($users, $invitations, new RecordingAuthorizationChecker(granted: true));

        $response = $controller(
            $this->caller(),
            new InviteUserRequest('second-admin@erpify.test', [Role::ADMIN->value], self::PASSWORD),
        );

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
        $this->assertCount(1, $invitations->saved);
    }

    #[Test]
    public function aWrongPasswordRefusesAnAdministratorInviteBeforeAnythingIsWritten(): void
    {
        $users = $this->usersWithTheCaller();
        $invitations = new InMemoryInvitationRepository();
        $controller = $this->controller($users, $invitations, new RecordingAuthorizationChecker(granted: true));

        try {
            $controller(
                $this->caller(),
                new InviteUserRequest('rogue@erpify.test', [Role::ADMIN->value], 'not-the-current-password'),
            );
            $this->fail('A wrong current password must be refused.');
        } catch (InvalidCurrentPassword) {
            // Expected — what the case asserts is what did NOT happen afterwards.
        }

        $this->assertSame([], $users->saved, 'an identity was provisioned for a caller who failed the proof');
        $this->assertSame([], $invitations->saved);
    }

    #[Test]
    public function aMissingPasswordIsAWrongOneForAnAdministratorInvite(): void
    {
        // The wire layer answers a blank password 422 first; this pins the controller's own floor, so a
        // payload that somehow got past validation still cannot mint an administrator without the proof.
        $users = $this->usersWithTheCaller();
        $controller = $this->controller(
            $users,
            new InMemoryInvitationRepository(),
            new RecordingAuthorizationChecker(granted: true),
        );

        $this->expectException(InvalidCurrentPassword::class);

        $controller($this->caller(), new InviteUserRequest('rogue@erpify.test', [Role::ADMIN->value]));
    }

    #[Test]
    public function aSpentBudgetRefusesAnAdministratorInviteBeforeTheFunnelRuns(): void
    {
        $users = $this->usersWithTheCaller();
        $invitations = new InMemoryInvitationRepository();
        $controller = $this->controller(
            $users,
            $invitations,
            new RecordingAuthorizationChecker(granted: true),
            budget: 1,
        );

        try {
            $controller($this->caller(), new InviteUserRequest('a@erpify.test', [Role::ADMIN->value], 'spends-it'));
        } catch (InvalidCurrentPassword) {
            // The budget is spent by the attempt, not by its outcome — which is what leaves none for below.
        }

        $this->expectException(RateLimitExceeded::class);

        try {
            $controller(
                $this->caller(),
                new InviteUserRequest('b@erpify.test', [Role::ADMIN->value], self::PASSWORD),
            );
        } finally {
            $this->assertSame([], $invitations->saved, 'an invitation was written while the caller was over budget');
        }
    }

    #[Test]
    public function invitingANonAdministratorNeitherNeedsAPasswordNorSpendsTheBudget(): void
    {
        // One token of budget and no password at all: if the non-admin path touched either, the second invite
        // or the final administrator invite (which does need the token) would fail.
        $invitations = new InMemoryInvitationRepository();
        $controller = $this->controller(
            $this->usersWithTheCaller(),
            $invitations,
            new RecordingAuthorizationChecker(granted: true),
            budget: 1,
        );

        $controller($this->caller(), new InviteUserRequest('one@erpify.test', [Role::EDITOR->value]));
        $controller($this->caller(), new InviteUserRequest('two@erpify.test', [Role::VIEWER->value]));
        $controller($this->caller(), new InviteUserRequest('three@erpify.test', [Role::ADMIN->value], self::PASSWORD));

        $this->assertCount(3, $invitations->saved);
    }

    #[Test]
    public function aRefusedCallerSpendsNoBudget(): void
    {
        $invitations = new InMemoryInvitationRepository();
        $sharedBudget = $this->limiter(1);
        $refusing = $this->controller(
            $this->usersWithTheCaller(),
            $invitations,
            new RecordingAuthorizationChecker(granted: false),
            limiter: $sharedBudget,
        );

        try {
            $refusing(
                $this->caller(),
                new InviteUserRequest('rogue@erpify.test', [Role::ADMIN->value], self::PASSWORD),
            );
        } catch (AccessDeniedException) {
            // Refused on permission, ahead of the proof.
        }

        // The same bucket, one token: it is still there for a caller who is permitted.
        $permitted = $this->controller(
            $this->usersWithTheCaller(),
            $invitations,
            new RecordingAuthorizationChecker(granted: true),
            limiter: $sharedBudget,
        );
        $response = $permitted(
            $this->caller(),
            new InviteUserRequest('ok@erpify.test', [Role::ADMIN->value], self::PASSWORD),
        );

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
    }

    private function usersWithTheCaller(): InMemoryUserRepository
    {
        return new InMemoryUserRepository($this->callerAggregate());
    }

    private function callerAggregate(): \Erpify\Iam\Identity\Domain\Entity\User
    {
        return UserMother::create(password: HashedPassword::fromHash(self::PASSWORD));
    }

    private function caller(): SecurityUser
    {
        return new SecurityUser($this->callerAggregate());
    }

    private function controller(
        InMemoryUserRepository $users,
        InMemoryInvitationRepository $invitations,
        RecordingAuthorizationChecker $authorization,
        int $budget = 5,
        ?RateLimiterFactory $limiter = null,
    ): CreateInvitationController {
        $limiter ??= $this->limiter($budget);

        return new CreateInvitationController(
            $this->sendInvitation($users, $invitations),
            new ProveActorPassword($users, new ProveCurrentPassword()),
            new PasswordHasher(new PasswordHasherFactory([SecurityUser::class => ['algorithm' => 'plaintext']])),
            new CurrentPasswordProofThrottle($limiter, new RequestStack()),
            $authorization,
        );
    }

    private function limiter(int $budget): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'per_identity', 'policy' => 'sliding_window', 'limit' => $budget, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );
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
