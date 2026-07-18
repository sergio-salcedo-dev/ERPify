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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Drives the thin invitation controller over the real {@see SendInvitation} funnel (Identity + Organization +
 * Invitation use cases on in-memory repositories) so the wire path proves out: the validated request maps to
 * the {@see Role} enum, the member is invited, and the answer is a bodyless 201 (the accept token stays a
 * secret, the identity stays out of the response).
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

    #[Test]
    public function itInvitesTheMemberAndAnswersABodylessCreated(): void
    {
        $users = new InMemoryUserRepository();
        $invitations = new InMemoryInvitationRepository();
        $controller = new CreateInvitationController($this->sendInvitation($users, $invitations));

        $response = $controller(
            new InviteUserRequest('Newbie@Erpify.Test', [Role::MANAGER->value, Role::VIEWER->value]),
        );

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('', (string) $response->getContent());

        $this->assertCount(1, $users->saved);
        $this->assertSame(IdentityStatus::INVITED, $users->saved[0]->status());
        $this->assertCount(1, $invitations->saved);
    }

    private function sendInvitation(
        InMemoryUserRepository $users,
        InMemoryInvitationRepository $invitations,
    ): SendInvitation {
        $organizations = new InMemoryOrganizationRepository();
        $organizations->save(Organization::provision(self::ORG_ID, 'ACME'));

        return new SendInvitation(
            new InviteUser($users, $this->passingValidator()),
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
