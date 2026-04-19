<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Frontoffice\Health\Infrastructure\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Frontoffice\Health\Infrastructure\Controller\HealthController;
use JsonException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[CoversNothing]
final class HealthControllerTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function testInvokeReturnsOkJsonWithAtomDatetime(): void
    {
        $healthController = new HealthController();
        $jsonResponse = $healthController();

        $this->assertSame(Response::HTTP_OK, $jsonResponse->getStatusCode(), (string) $jsonResponse->getContent());
        $this->assertStringContainsString('application/json', (string) $jsonResponse->headers->get('Content-Type'));

        $data = \json_decode($jsonResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $data['status']);
        $this->assertArrayHasKey('datetime', $data);
        $this->assertIsString($data['datetime']);

        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $data['datetime']);
        $this->assertInstanceOf(DateTimeImmutable::class, $parsed, 'datetime must be ISO-8601 (ATOM)');
    }
}
