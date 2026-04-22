<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Frontoffice\Mercure\Infrastructure\Controller;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
#[CoversNothing]
final class MercureBootstrapFunctionalTest extends WebTestCase
{
    public function testBootstrapReturnsTopicHubLinkAndMercureCookie(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->request(Request::METHOD_GET, '/api/v1/mercure/bootstrap');

        self::assertResponseIsSuccessful();
        $payload = \json_decode((string) $kernelBrowser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('topic', $payload);
        $this->assertArrayHasKey('hubUrl', $payload);
        $this->assertSame('urn:erpify:mercure:demo', $payload['topic']);
        $hubUrl = $payload['hubUrl'];
        $this->assertIsString($hubUrl);
        $this->assertStringContainsString('.well-known/mercure', $hubUrl);

        $link = $kernelBrowser->getResponse()->headers->get('Link');
        $this->assertNotNull($link);
        $this->assertStringContainsString('rel="mercure"', $link);

        $setCookie = $kernelBrowser->getResponse()->headers->get('set-cookie');
        $this->assertNotNull($setCookie);
        $this->assertStringContainsString('mercureAuthorization', $setCookie);
    }
}
