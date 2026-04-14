<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Frontoffice;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FrankenPhpHotReloadFunctionalTest extends WebTestCase
{
    public function testBootstrapReturnsJsonShape(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/api/v1/dev/frankenphp-hot-reload');

        self::assertResponseIsSuccessful();
        $payload = json_decode($kernelBrowser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('enabled', $payload);
        $this->assertIsBool($payload['enabled']);

        if ($payload['enabled']) {
            $this->assertArrayHasKey('subscribePath', $payload);
            $this->assertIsString($payload['subscribePath']);
            $this->assertStringContainsString('.well-known/mercure', $payload['subscribePath']);
        }
    }
}
