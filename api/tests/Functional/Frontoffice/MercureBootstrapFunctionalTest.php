<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Frontoffice;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MercureBootstrapFunctionalTest extends WebTestCase
{
    public function testBootstrapReturnsTopicHubLinkAndMercureCookie(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/api/v1/mercure/bootstrap');

        self::assertResponseIsSuccessful();
        $payload = json_decode($kernelBrowser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('urn:erpify:mercure:demo', $payload['topic']);
        $this->assertIsString($payload['hubUrl']);
        $this->assertStringContainsString('.well-known/mercure', $payload['hubUrl']);

        $link = $kernelBrowser->getResponse()->headers->get('Link');
        $this->assertNotNull($link);
        $this->assertStringContainsString('rel="mercure"', $link);

        $setCookie = $kernelBrowser->getResponse()->headers->get('set-cookie');
        $this->assertNotNull($setCookie);
        $this->assertStringContainsString('mercureAuthorization', $setCookie);
    }
}
