<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
#[CoversNothing]
final class BankLogoMultipartFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    private const string MIN_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5'
        . '+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';

    private const string PNG_MIME = 'image/png';

    public function testPostMultipartBankWithLogoReturnsLogoUrlAndServesImage(): void
    {
        $kernelBrowser = self::createClient();
        $this->authenticateClient($kernelBrowser);

        $tmp = \tempnam(\sys_get_temp_dir(), 'erpify_logo');
        $this->assertNotFalse($tmp);
        \file_put_contents($tmp, \base64_decode(self::MIN_PNG, true));
        $uploadedFile = new UploadedFile($tmp, 'logo.png', self::PNG_MIME, null, true);

        $suffix = \bin2hex(\random_bytes(4));
        $name = 'Test Logo Bank Multipart ' . $suffix;
        $shortName = 'LBM' . $suffix;

        $kernelBrowser->request(
            Request::METHOD_POST,
            '/api/v1/backoffice/banks',
            [
                'name' => $name,
                'shortName' => $shortName,
            ],
            ['image' => $uploadedFile],
        );

        self::assertResponseStatusCodeSame(201);
        $body = \json_decode((string) $kernelBrowser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $payload = $body['data'];
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('name', $payload);
        $this->assertArrayHasKey('logoUrl', $payload);
        $this->assertSame($name, $payload['name']);
        $logoUrl = $payload['logoUrl'];
        $this->assertIsString($logoUrl);
        $this->assertMatchesRegularExpression('#/api/v1/media/[a-f0-9]{64}(?:\?.*)?$#', $logoUrl);
        $path = \parse_url($logoUrl, PHP_URL_PATH);
        $this->assertIsString($path);

        $kernelBrowser->request(Request::METHOD_GET, $path);
        self::assertResponseIsSuccessful();
        $this->assertSame(self::PNG_MIME, $kernelBrowser->getResponse()->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'immutable',
            (string) $kernelBrowser->getResponse()->headers->get('Cache-Control'),
        );
        $this->assertNotNull($kernelBrowser->getResponse()->headers->get('ETag'));
    }

    /**
     * The file part rides `$request->files` and never reaches the extra-attribute check, so the
     * upload survives strict mapping. A surplus *scalar* does land in `$request->request`, which is
     * what the payload is mapped from — so `form` is closed on exactly the same terms as `json`,
     * and an HTML form posting its submit button would be refused rather than silently trimmed.
     */
    public function testMultipartRejectsASurplusFormFieldWhileTheFilePartIsIgnored(): void
    {
        $kernelBrowser = self::createClient();
        $this->authenticateClient($kernelBrowser);

        $tmp = \tempnam(\sys_get_temp_dir(), 'erpify_logo');
        $this->assertNotFalse($tmp);
        \file_put_contents($tmp, \base64_decode(self::MIN_PNG, true));
        $uploadedFile = new UploadedFile($tmp, 'logo.png', self::PNG_MIME, null, true);

        $suffix = \bin2hex(\random_bytes(4));

        $kernelBrowser->request(
            Request::METHOD_POST,
            '/api/v1/backoffice/banks',
            [
                'name' => 'Surplus Field Bank ' . $suffix,
                'shortName' => 'SFB' . $suffix,
                'submit' => 'Save',
            ],
            ['image' => $uploadedFile],
        );

        self::assertResponseStatusCodeSame(422);
        $body = \json_decode((string) $kernelBrowser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('type', $body);
        $this->assertSame('validation-failed', $body['type']);
        $this->assertArrayHasKey('violations', $body);
        $this->assertIsArray($body['violations']);
        $this->assertCount(1, $body['violations']);
    }

    public function testMediaGetReturns304WhenEtagMatches(): void
    {
        $kernelBrowser = self::createClient();
        $this->authenticateClient($kernelBrowser);

        $tmp = \tempnam(\sys_get_temp_dir(), 'erpify_logo2');
        $this->assertNotFalse($tmp);
        \file_put_contents($tmp, \base64_decode(self::MIN_PNG, true));
        $uploadedFile = new UploadedFile($tmp, 'logo.png', self::PNG_MIME, null, true);

        $suffix = \bin2hex(\random_bytes(4));
        $name = 'Test Etag Bank ' . $suffix;
        $shortName = 'ETB' . $suffix;

        $kernelBrowser->request(
            Request::METHOD_POST,
            '/api/v1/backoffice/banks',
            ['name' => $name, 'shortName' => $shortName],
            ['image' => $uploadedFile],
        );
        $body = \json_decode((string) $kernelBrowser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $payload = $body['data'];
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('logoUrl', $payload);
        $logoUrl = $payload['logoUrl'];
        $this->assertIsString($logoUrl);
        $this->assertMatchesRegularExpression('#/api/v1/media/[a-f0-9]{64}(?:\?.*)?$#', $logoUrl);
        $path = \parse_url($logoUrl, PHP_URL_PATH);
        $this->assertIsString($path);

        $kernelBrowser->request(Request::METHOD_GET, $path);
        $etag = $kernelBrowser->getResponse()->headers->get('ETag');
        $this->assertNotNull($etag);

        $kernelBrowser->request(Request::METHOD_GET, $path, server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
    }
}
