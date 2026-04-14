<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Frontoffice\Dev\Infrastructure\Controller;

use Erpify\Frontoffice\Dev\Infrastructure\Controller\FrankenPhpHotReloadController;
use JsonException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[CoversNothing]
final class FrankenPhpHotReloadControllerTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function testInvokeReturnsDisabledWhenServerVarMissing(): void
    {
        // Create an empty request (no FRANKENPHP_HOT_RELOAD)
        $request = new Request;
        $response = (new FrankenPhpHotReloadController)($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $data = \json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($data['enabled']);
        $this->assertArrayNotHasKey('subscribePath', $data);
    }

    /**
     * @throws JsonException
     */
    public function testInvokeReturnsPathWhenServerVarSet(): void
    {
        $path = '/.well-known/mercure?topic=https%3A%2F%2Ffrankenphp.dev%2Fhot-reload%2Fabc';

        // Inject the server variable directly into the Request object
        $request = new Request(server: ['FRANKENPHP_HOT_RELOAD' => $path]);

        $response = (new FrankenPhpHotReloadController)($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $data = \json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($data['enabled']);
        $this->assertSame($path, $data['subscribePath']);
    }
}
