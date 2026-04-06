<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Frontoffice;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FrankenPhpHotReloadFunctionalTest extends WebTestCase
{
    public function testBootstrapReturnsJsonShape(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/dev/frankenphp-hot-reload');

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('enabled', $payload);
        self::assertIsBool($payload['enabled']);

        if ($payload['enabled']) {
            self::assertArrayHasKey('subscribePath', $payload);
            self::assertIsString($payload['subscribePath']);
            self::assertStringContainsString('.well-known/mercure', $payload['subscribePath']);
        }
    }
}
