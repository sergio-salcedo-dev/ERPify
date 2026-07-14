<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\RequestPasswordReset;
use Erpify\Iam\Identity\Application\SendPasswordResetEmailBestEffort;
use Erpify\Iam\Identity\Infrastructure\Http\ForgotPasswordRequest;
use Erpify\Iam\Identity\Infrastructure\Http\RequestPasswordResetController;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordRecoveryThrottle;
use Erpify\Tests\Unit\Iam\Identity\Application\CountingPreIdentityTimingFloor;
use Erpify\Tests\Unit\Iam\Identity\Application\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryPasswordResetTokenRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\RecordingEventBus;
use Erpify\Tests\Unit\Iam\Identity\Application\RecordingPasswordResetEmailSender;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Weaves the real {@see RequestPasswordReset} graph (a `final` use case, with its in-memory collaborators)
 * rather than a mock, so the throttled path can prove the work is truly silenced — hence the coupling this
 * contract legitimately carries.
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
        $controller = $this->controller($tokens, perEmailLimit: 5, floor: new CountingPreIdentityTimingFloor());

        $response = $controller(new ForgotPasswordRequest(UserMother::DEFAULT_EMAIL));

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode(), (string) $response->getContent());
        $this->assertCount(1, $tokens->saved);
    }

    public function testASaturatedTargetGetsTheSame202WithTheWorkSilencedAndTheFloorPaid(): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository();
        $floor = new CountingPreIdentityTimingFloor();
        $controller = $this->controller($tokens, perEmailLimit: 1, floor: $floor);

        $first = $controller(new ForgotPasswordRequest(UserMother::DEFAULT_EMAIL));
        $throttled = $controller(new ForgotPasswordRequest(UserMother::DEFAULT_EMAIL));

        // Saturation must be invisible: identical status and body, no second token minted, and the timing
        // floor still paid so the silenced answer is not distinguishable by latency either.
        $this->assertSame($first->getStatusCode(), $throttled->getStatusCode());
        $this->assertSame($first->getContent(), $throttled->getContent());
        $this->assertCount(1, $tokens->saved);
        $this->assertSame(2, $floor->invocations);
    }

    private function controller(
        InMemoryPasswordResetTokenRepository $tokens,
        int $perEmailLimit,
        CountingPreIdentityTimingFloor $floor,
    ): RequestPasswordResetController {
        $useCase = new RequestPasswordReset(
            new InMemoryUserRepository(UserMother::create()),
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

        return new RequestPasswordResetController(
            $useCase,
            new PasswordRecoveryThrottle($limiter('per_email', $perEmailLimit), $limiter('per_selector', 5)),
            $floor,
        );
    }
}
