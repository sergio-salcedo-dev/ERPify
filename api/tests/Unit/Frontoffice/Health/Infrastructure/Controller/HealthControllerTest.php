<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Frontoffice\Health\Infrastructure\Controller;

use Erpify\Frontoffice\Health\Infrastructure\Controller\HealthController;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

final class HealthControllerTest extends TestCase
{
    public function testInvokeReturnsOkJsonWithAtomDatetime(): void
    {
        $healthController = new HealthController();
        $jsonResponse = $healthController();

        $this->assertInstanceOf(JsonResponse::class, $jsonResponse);
        $this->assertSame(\Symfony\Component\HttpFoundation\Response::HTTP_OK, $jsonResponse->getStatusCode(), (string) $jsonResponse->getContent());
        $this->assertStringContainsString('application/json', (string) $jsonResponse->headers->get('Content-Type'));

        $data = json_decode($jsonResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $data['status']);
        $this->assertArrayHasKey('datetime', $data);
        $this->assertIsString($data['datetime']);

        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $data['datetime']);
        $this->assertInstanceOf(DateTimeImmutable::class, $parsed, 'datetime must be ISO-8601 (ATOM)');
    }
}
