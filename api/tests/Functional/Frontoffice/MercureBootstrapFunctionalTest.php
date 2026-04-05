<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Frontoffice;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MercureBootstrapFunctionalTest extends WebTestCase
{
    public function testBootstrapReturnsTopicHubLinkAndMercureCookie(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/mercure/bootstrap');

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('urn:erpify:mercure:demo', $payload['topic']);
        self::assertIsString($payload['hubUrl']);
        self::assertStringContainsString('.well-known/mercure', $payload['hubUrl']);

        $link = $client->getResponse()->headers->get('Link');
        self::assertNotNull($link);
        self::assertStringContainsString('rel="mercure"', $link);

        $setCookie = $client->getResponse()->headers->get('set-cookie');
        self::assertNotNull($setCookie);
        self::assertStringContainsString('mercureAuthorization', $setCookie);
    }
}
