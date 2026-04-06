<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Frontoffice\Dev\Infrastructure\Controller;

use Erpify\Frontoffice\Dev\Infrastructure\Controller\FrankenPhpHotReloadController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

final class FrankenPhpHotReloadControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['FRANKENPHP_HOT_RELOAD']);
        parent::tearDown();
    }

    public function testInvokeReturnsDisabledWhenServerVarMissing(): void
    {
        unset($_SERVER['FRANKENPHP_HOT_RELOAD']);

        $response = (new FrankenPhpHotReloadController())();

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($data['enabled']);
        self::assertArrayNotHasKey('subscribePath', $data);
    }

    public function testInvokeReturnsPathWhenServerVarSet(): void
    {
        $_SERVER['FRANKENPHP_HOT_RELOAD'] = '/.well-known/mercure?topic=https%3A%2F%2Ffrankenphp.dev%2Fhot-reload%2Fabc';

        $response = (new FrankenPhpHotReloadController())();

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['enabled']);
        self::assertSame($_SERVER['FRANKENPHP_HOT_RELOAD'], $data['subscribePath']);
        self::assertStringContainsString('.well-known/mercure', $data['subscribePath']);
    }
}
