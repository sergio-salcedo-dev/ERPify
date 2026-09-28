<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Http\RedeemRecoverySecretController;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Infrastructure\Persistence\Doctrine\DoctrineSessionRepository;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\Iam\Session\Fixtures\RevokingOnFirstLockSessionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The interrupted redemption, end to end through the kernel: the 503 invites a retry, and the retry from the
 * SAME browser has to succeed.
 *
 * Only the kernel can say so. The device is signed in by the time the eviction finds its session gone, and
 * `ContextListener` re-serialises whatever token storage holds into the session on `kernel.response` — so a
 * controller that merely invalidates the native session hands back a regenerated cookie still carrying the
 * token, and the admission gate refuses the retry with 401 because that token has no session correlation. A
 * unit test reading the session before the response listener runs is green over exactly that.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") — the property is the whole kernel's, so the test seeds an
 * organization, a membership, an identity and its secret through their own aggregates and swaps one adapter;
 * each of those types is a real participant rather than an avoidable dependency.
 */
#[CoversClass(RedeemRecoverySecretController::class)]
final class RedeemInterruptedRetryFunctionalTest extends WebTestCase
{
    private const string PATH = '/api/v1/backoffice/recovery/redeem';

    #[Test]
    public function theRetryTheInterruptionInvitesSucceedsFromTheSameBrowser(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        // Built by hand rather than fetched: the container's instance IS the alias being replaced, and resolving
        // it first would initialise the very service `set()` must still be allowed to override.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $clock = self::getContainer()->get(Clock::class);
        $this->assertInstanceOf(Clock::class, $clock);
        $interfering = new RevokingOnFirstLockSessionRepository(new DoctrineSessionRepository($entityManager, $clock));
        self::getContainer()->set(SessionRepository::class, $interfering);

        $plaintext = $this->seedIdentityHoldingASecret();

        $this->redeem($client, $plaintext);
        $interrupted = $client->getResponse();

        $this->assertTrue(
            $interfering->interfered(),
            'the interleaving was never forced, so nothing was tested: ' . $interrupted->getContent(),
        );
        $this->assertSame(
            Response::HTTP_SERVICE_UNAVAILABLE,
            $interrupted->getStatusCode(),
            (string) $interrupted->getContent(),
        );
        $this->assertStringContainsString('transient-transaction-failure', (string) $interrupted->getContent());

        $this->redeem($client, $plaintext);
        $retried = $client->getResponse();

        $this->assertSame(
            Response::HTTP_NO_CONTENT,
            $retried->getStatusCode(),
            'the retry the 503 invites was refused from the browser that received it: '
            . $retried->getContent(),
        );
    }

    private function redeem(KernelBrowser $client, string $plaintext): void
    {
        $client->request(
            Request::METHOD_POST,
            self::PATH,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => 'http://localhost',
                'HTTP_X_CSRF_TOKEN' => 'behat-stateless-csrf-nonce-000000',
            ],
            content: \json_encode(['secret' => $plaintext], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * An `ACTIVE` identity holding one recovery secret, minted on the clock the redemption reads, and belonging
     * to an organization.
     */
    private function seedIdentityHoldingASecret(): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $clock = self::getContainer()->get(Clock::class);
        $this->assertInstanceOf(Clock::class, $clock);

        $userId = Uuid::generate();
        $user = User::register($userId, \sprintf('interrupted-%s@erpify.test', $userId), HashedPassword::fromHash(
            '$2y$04$abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ012',
        ), Role::AUDIT_READER);
        $user->pullDomainEvents();

        $generated = RecoverySecret::mint($userId, $clock->now());
        $generated->secret->pullDomainEvents();

        // The login mints its session inside the identity's organization, so an identity with no membership
        // is refused before any lock is taken and the interleaving under test is never reached.
        $organizationId = Uuid::generate();
        $organization = Organization::provision($organizationId, 'Interrupted redemption');
        $organization->pullDomainEvents();

        $membership = Membership::grant(Uuid::generate(), $userId, $organizationId);
        $membership->pullDomainEvents();

        $entityManager->persist($organization);
        $entityManager->persist($membership);
        $entityManager->persist($user);
        $entityManager->persist($generated->secret);
        $entityManager->flush();
        $entityManager->clear();

        return $generated->plaintext();
    }
}
