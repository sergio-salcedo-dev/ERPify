<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Health\Infrastructure\Controller;

use Erpify\Backoffice\Health\Application\CheckDatabaseHealth;
use Erpify\Backoffice\Health\Domain\DatabaseHealthChecker;
use Erpify\Backoffice\Health\Infrastructure\Controller\DatabaseHealthController;
use Erpify\Shared\Clock\Infrastructure\SymfonyClock;
use Erpify\Shared\Http\Infrastructure\Responder\JsonResponder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[CoversClass(DatabaseHealthController::class)]
final class DatabaseHealthControllerTest extends TestCase
{
    private const string FROZEN_INSTANT = '2026-06-14T12:00:00+00:00';

    #[Test]
    #[DataProvider('provideItReportsTheProbeOutcomeWithTheClockInstantCases')]
    public function itReportsTheProbeOutcomeWithTheClockInstant(bool $reachable, string $expectedStatus): void
    {
        $checker = $this->createStub(DatabaseHealthChecker::class);
        $checker->method('isHealthy')->willReturn($reachable);

        $controller = new DatabaseHealthController(
            new JsonResponder(),
            new CheckDatabaseHealth($checker),
            new SymfonyClock(new MockClock(self::FROZEN_INSTANT)),
        );

        $response = $controller();

        $payload = \json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(
            ['data' => ['status' => $expectedStatus, 'service' => 'Database', 'datetime' => self::FROZEN_INSTANT]],
            $payload,
        );
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function provideItReportsTheProbeOutcomeWithTheClockInstantCases(): iterable
    {
        yield 'reachable database reports ok' => [true, 'ok'];
        yield 'unreachable database reports error' => [false, 'error'];
    }
}
