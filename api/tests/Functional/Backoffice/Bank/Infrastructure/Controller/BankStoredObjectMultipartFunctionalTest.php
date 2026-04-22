<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank\Infrastructure\Controller;

use JsonException;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
#[CoversNothing]
final class BankStoredObjectMultipartFunctionalTest extends WebTestCase
{
    private const string MIN_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';

    /**
     * @throws JsonException
     */
    public function testPostMultipartBankWithStoredObjectReturnsUrlAndServesImage(): void
    {
        $kernelBrowser = self::createClient();

        $tmp = \tempnam(\sys_get_temp_dir(), 'erpify_stored');
        $this->assertNotFalse($tmp);
        \file_put_contents($tmp, \base64_decode(self::MIN_PNG, true));
        $uploadedFile = new UploadedFile($tmp, 'extra.png', 'image/png', null, true);

        $kernelBrowser->request(
            Request::METHOD_POST,
            '/api/v1/backoffice/banks',
            [
                'name' => 'Stored Object Bank',
                'short_name' => 'SOB',
            ],
            ['stored_object' => $uploadedFile],
        );

        self::assertResponseStatusCodeSame(201);
        $payload = \json_decode((string) $kernelBrowser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('name', $payload);
        $this->assertArrayHasKey('storedObjectUrl', $payload);
        $this->assertSame('Stored Object Bank', $payload['name']);
        $this->assertNull($payload['logoUrl'] ?? null);
        $storedObjectUrl = $payload['storedObjectUrl'];
        $this->assertIsString($storedObjectUrl);
        $this->assertMatchesRegularExpression(
            '#/api/v1/stored-objects/([a-f0-9]{64})(?:\?.*)?$#',
            $storedObjectUrl,
            $storedObjectUrl,
        );
        $path = \parse_url($storedObjectUrl, PHP_URL_PATH);
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
}
