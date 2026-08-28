<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\RecordRecoveryThrottleAuditBestEffort;
use Erpify\Iam\Identity\Application\RequestPasswordReset;
use Erpify\Iam\Identity\Application\SendPasswordResetEmailBestEffort;
use Erpify\Iam\Identity\Infrastructure\Http\ForgotPasswordRequest;
use Erpify\Iam\Identity\Infrastructure\Http\RecoveryThrottleAuditListener;
use Erpify\Iam\Identity\Infrastructure\Http\RequestPasswordResetController;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordRecoveryThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\RateLimiterRecoveryThrottleAuditBudget;
use Erpify\Tests\Unit\Iam\Identity\Application\CountingPreIdentityTimingFloor;
use Erpify\Tests\Unit\Iam\Identity\Application\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryPasswordResetTokenRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\RecordingEventBus;
use Erpify\Tests\Unit\Iam\Identity\Application\RecordingPasswordResetEmailSender;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Weaves the real {@see RequestPasswordReset} graph (a `final` use case, with its in-memory collaborators)
 * rather than a mock, so the throttled path can prove the work is truly silenced — hence the coupling this
 * contract legitimately carries. The audit budget is the real limiter over in-memory storage for the same
 * reason: a fake that answered "allowed" on a schedule of its own would make the amplification bound a
 * property of the double.
 *
 * The projection runs on `kernel.terminate`, so every case that asserts a row drives the listener explicitly
 * after the controller. That is the production sequence, not a shortcut — and a case that forgot to drive it
 * would assert zero rows and fail loudly rather than pass.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(RequestPasswordResetController::class)]
final class RequestPasswordResetControllerTest extends TestCase
{
    public function testWithinBudgetTheUseCaseRunsAndTheUniform202IsReturned(): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository();
        $auditLogger = new RecordingAuditLogger();
        [$controller, $listener] = $this->endpoint($tokens, 5, new CountingPreIdentityTimingFloor(), $auditLogger);

        $response = $this->drive($controller, $listener, UserMother::DEFAULT_EMAIL);

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode(), (string) $response->getContent());
        $this->assertCount(1, $tokens->saved);
        $this->assertSame([], $auditLogger->records, 'A served request is not a refusal and records nothing.');
    }

    public function testASaturatedTargetGetsTheSame202WithTheWorkSilencedAndTheFloorPaid(): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository();
        $floor = new CountingPreIdentityTimingFloor();
        [$controller, $listener] = $this->endpoint($tokens, 1, $floor, new RecordingAuditLogger());

        $first = $this->drive($controller, $listener, UserMother::DEFAULT_EMAIL);
        $throttled = $this->drive($controller, $listener, UserMother::DEFAULT_EMAIL);

        // Saturation must be invisible: identical status, body and headers, no second token minted, and the
        // timing floor still paid so the silenced answer is not distinguishable by latency either.
        $this->assertSame($first->getStatusCode(), $throttled->getStatusCode());
        $this->assertSame($first->getContent(), $throttled->getContent());
        $this->assertSame($first->headers->all(), $throttled->headers->all());
        $this->assertCount(1, $tokens->saved);
        $this->assertSame(2, $floor->invocations);
    }

    public function testNoRateLimitHeaderEverDescribesThePerTargetBudget(): void
    {
        // The sibling CurrentPasswordProofThrottle stamps a RateLimitSnapshot so its 429 can carry RateLimit-*
        // describing the budget that refused. Doing the same here would turn the uniform 202 into a
        // per-account existence oracle — the response would answer what the status deliberately does not.
        $floor = new CountingPreIdentityTimingFloor();
        [$controller, $listener] = $this->endpoint(
            new InMemoryPasswordResetTokenRepository(),
            1,
            $floor,
            new RecordingAuditLogger(),
        );

        $this->drive($controller, $listener, UserMother::DEFAULT_EMAIL);
        $throttled = $this->drive($controller, $listener, UserMother::DEFAULT_EMAIL);

        foreach (\array_keys($throttled->headers->all()) as $name) {
            $this->assertStringNotContainsStringIgnoringCase('ratelimit', $name);
            $this->assertStringNotContainsStringIgnoringCase('retry-after', $name);
        }
    }

    public function testTheRefusalIsProjectedOnceHoweverManyRefusalsFollowIt(): void
    {
        // The bound this control exists for. Past exhaustion every later request is refused too, so without
        // a budget of its own the projection would be one write per attempt — handed to the attacker it
        // records. Deleting the guard in RecordRecoveryThrottleAuditBestEffort turns the count below into 7.
        $auditLogger = new RecordingAuditLogger();
        [$controller, $listener] = $this->endpoint(
            new InMemoryPasswordResetTokenRepository(),
            1,
            new CountingPreIdentityTimingFloor(),
            $auditLogger,
        );

        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $this->drive($controller, $listener, UserMother::DEFAULT_EMAIL);
        }

        $this->assertCount(1, $auditLogger->records);
        $this->assertSame('PASSWORD_RECOVERY_THROTTLED', $auditLogger->records[0]['action']);
    }

    public function testTheProjectionIsKeyedPerAddressSoOneTargetDoesNotSilenceAnother(): void
    {
        $auditLogger = new RecordingAuditLogger();
        [$controller, $listener] = $this->endpoint(
            new InMemoryPasswordResetTokenRepository(),
            1,
            new CountingPreIdentityTimingFloor(),
            $auditLogger,
        );

        foreach ([UserMother::DEFAULT_EMAIL, 'someone-else@erpify.test'] as $email) {
            $this->drive($controller, $listener, $email);
            $this->drive($controller, $listener, $email);
        }

        $this->assertCount(2, $auditLogger->records, 'A global key would let one target suppress every other.');
    }

    /**
     * One production round trip: the controller answers, then the kernel terminates and the projection runs.
     */
    private function drive(
        RequestPasswordResetController $controller,
        RecoveryThrottleAuditListener $listener,
        string $email,
    ): Response {
        $request = Request::create('/api/v1/backoffice/forgot-password', Request::METHOD_POST);
        $response = $controller($request, new ForgotPasswordRequest($email));

        $listener->onTerminate(new TerminateEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            $response,
        ));

        return $response;
    }

    /**
     * @return array{RequestPasswordResetController, RecoveryThrottleAuditListener}
     */
    private function endpoint(
        InMemoryPasswordResetTokenRepository $tokens,
        int $perEmailLimit,
        CountingPreIdentityTimingFloor $floor,
        RecordingAuditLogger $auditLogger,
    ): array {
        $users = new InMemoryUserRepository(UserMother::create());

        $useCase = new RequestPasswordReset(
            $users,
            $tokens,
            new RecordingEventBus(),
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable('2026-07-14T12:00:00+00:00')),
            new SendPasswordResetEmailBestEffort(new RecordingPasswordResetEmailSender(), new NullLogger()),
            $floor,
        );

        $limiter = static fn (string $id, int $limit): RateLimiterFactory => new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );

        return [
            new RequestPasswordResetController(
                $useCase,
                new PasswordRecoveryThrottle($limiter('per_email', $perEmailLimit), $limiter('per_selector', 5)),
                $floor,
            ),
            new RecoveryThrottleAuditListener(
                new RecordRecoveryThrottleAuditBestEffort(
                    new RateLimiterRecoveryThrottleAuditBudget($limiter('audit', 1)),
                    $users,
                    $auditLogger,
                    new NullLogger(),
                ),
                new RequestStack(),
            ),
        ];
    }
}
