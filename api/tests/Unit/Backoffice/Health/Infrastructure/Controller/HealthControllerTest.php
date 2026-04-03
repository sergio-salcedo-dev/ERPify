<?php

declare(strict_types=1);

namespace Erpify\tests\Unit\Backoffice\Health\Infrastructure\Controller;

use Erpify\Backoffice\Health\Infrastructure\Controller\HealthController;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

final class HealthControllerTest extends TestCase
{
    public function testInvokeReturnsOkJsonWithAtomDatetime(): void
    {
        $controller = new HealthController();
        $response = $controller();

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $data['status']);
        self::assertArrayHasKey('datetime', $data);
        self::assertIsString($data['datetime']);

        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $data['datetime']);
        self::assertInstanceOf(DateTimeImmutable::class, $parsed, 'datetime must be ISO-8601 (ATOM)');
    }
}
