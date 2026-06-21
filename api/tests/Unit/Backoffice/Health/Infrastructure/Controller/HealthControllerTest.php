<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Health\Infrastructure\Controller;

use Erpify\Backoffice\Health\Infrastructure\Controller\HealthController;
use Erpify\Shared\Clock\Infrastructure\SymfonyClock;
use Erpify\Shared\Http\Infrastructure\Responder\JsonResponder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[CoversClass(HealthController::class)]
final class HealthControllerTest extends TestCase
{
    private const string FROZEN_INSTANT = '2026-06-14T12:00:00+00:00';

    #[Test]
    public function itReportsTheClockInstantAsTheHealthDatetime(): void
    {
        $controller = new HealthController(
            new JsonResponder(),
            new SymfonyClock(new MockClock(self::FROZEN_INSTANT)),
        );

        $response = $controller();

        $payload = \json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(
            ['data' => ['status' => 'ok', 'service' => 'Back office', 'datetime' => self::FROZEN_INSTANT]],
            $payload,
        );
    }
}
