<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank\Infrastructure\Controller;

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
    private const string MIN_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';

    public function testPostMultipartBankWithLogoReturnsLogoUrlAndServesImage(): void
    {
        $kernelBrowser = self::createClient();

        $tmp = \tempnam(\sys_get_temp_dir(), 'erpify_logo');
        $this->assertNotFalse($tmp);
        \file_put_contents($tmp, \base64_decode(self::MIN_PNG, true));
        $uploadedFile = new UploadedFile($tmp, 'logo.png', 'image/png', null, true);

        $kernelBrowser->request(
            Request::METHOD_POST,
            '/api/v1/backoffice/banks',
            [
                'name' => 'Logo Bank Multipart',
                'short_name' => 'LBM',
            ],
            ['image' => $uploadedFile],
        );

        self::assertResponseStatusCodeSame(201);
        $payload = \json_decode((string) $kernelBrowser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('name', $payload);
        $this->assertArrayHasKey('logoUrl', $payload);
        $this->assertSame('Logo Bank Multipart', $payload['name']);
        $logoUrl = $payload['logoUrl'];
        $this->assertIsString($logoUrl);
        $this->assertMatchesRegularExpression('#/api/v1/media/[a-f0-9]{64}(?:\?.*)?$#', $logoUrl);
        $path = \parse_url($logoUrl, PHP_URL_PATH);
        $this->assertIsString($path);

        $kernelBrowser->request(Request::METHOD_GET, $path);
        self::assertResponseIsSuccessful();
        $this->assertSame('image/png', $kernelBrowser->getResponse()->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'immutable',
            (string) $kernelBrowser->getResponse()->headers->get('Cache-Control'),
        );
        $this->assertNotNull($kernelBrowser->getResponse()->headers->get('ETag'));
    }

    public function testMediaGetReturns304WhenEtagMatches(): void
    {
        $kernelBrowser = self::createClient();

        $tmp = \tempnam(\sys_get_temp_dir(), 'erpify_logo2');
        $this->assertNotFalse($tmp);
        \file_put_contents($tmp, \base64_decode(self::MIN_PNG, true));
        $uploadedFile = new UploadedFile($tmp, 'logo.png', 'image/png', null, true);

        $kernelBrowser->request(
            Request::METHOD_POST,
            '/api/v1/backoffice/banks',
            ['name' => 'Etag Bank', 'short_name' => 'ETB'],
            ['image' => $uploadedFile],
        );
        $payload = \json_decode((string) $kernelBrowser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
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
