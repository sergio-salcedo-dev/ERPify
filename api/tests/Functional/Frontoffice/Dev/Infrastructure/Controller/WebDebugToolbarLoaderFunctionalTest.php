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

        // The PWA host mounts this fragment's head nodes because the stylesheet arrives as a
        // LEADING <link>, which the HTML parser hoists out of the body. That coupling lives in
        // the vendor template, so it is asserted here against the real render rather than
        // against a transcription in the PWA suite. Read what it buys honestly: it reds when
        // upstream REMOVES or renames the sheet, never when upstream adds one — the direction
        // that produced the defect in the first place.
        $this->assertStringContainsString('rel="stylesheet"', $body);
        $this->assertStringContainsString('/_wdt/styles', $body);
    }
}
