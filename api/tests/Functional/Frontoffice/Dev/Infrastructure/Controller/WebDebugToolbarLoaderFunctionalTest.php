<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Frontoffice\Dev\Infrastructure\Controller;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
#[CoversNothing]
final class WebDebugToolbarLoaderFunctionalTest extends WebTestCase
{
    public function testRendersToolbarLoaderForToken(): void
    {
        $kernelBrowser = self::createClient();
        $token = 'abc123';
        $kernelBrowser->request(Request::METHOD_GET, '/_dev/wdt-loader/' . $token);

        $response = $kernelBrowser->getResponse();
        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));

        $body = (string) $response->getContent();
        // The loader is the real Symfony toolbar bootstrap, parameterised by the token, that
        // pulls the bare toolbar markup from /_wdt/{token} and wires up the Sfjs runtime.
        $this->assertStringContainsString("Sfjs.loadToolbar('" . $token . "')", $body);
        $this->assertStringContainsString('Sfjs', $body);
        $this->assertStringContainsString('/_wdt/', $body);
    }
}
