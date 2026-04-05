<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class BankLogoMultipartFunctionalTest extends WebTestCase
{
    private const MIN_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';

    public function testPostMultipartBankWithLogoReturnsLogoUrlAndServesImage(): void
    {
        $client = static::createClient();

        $tmp = tempnam(sys_get_temp_dir(), 'erpify_logo');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, base64_decode(self::MIN_PNG, true));
        $upload = new UploadedFile($tmp, 'logo.png', 'image/png', null, true);

        $client->request(
            'POST',
            '/api/v1/backoffice/banks',
            [
                'name' => 'Logo Bank Multipart',
                'short_name' => 'LBM',
            ],
            ['image' => $upload],
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Logo Bank Multipart', $payload['name']);
        self::assertIsString($payload['logoUrl']);
        self::assertSame(1, preg_match('#/api/v1/media/([a-f0-9]{64})(?:\?.*)?$#', $payload['logoUrl'], $m), $payload['logoUrl']);
        $path = '/api/v1/media/'.$m[1];

        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('immutable', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertNotNull($client->getResponse()->headers->get('ETag'));
    }

    public function testMediaGetReturns304WhenEtagMatches(): void
    {
        $client = static::createClient();

        $tmp = tempnam(sys_get_temp_dir(), 'erpify_logo2');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, base64_decode(self::MIN_PNG, true));
        $upload = new UploadedFile($tmp, 'logo.png', 'image/png', null, true);

        $client->request(
            'POST',
            '/api/v1/backoffice/banks',
            ['name' => 'Etag Bank', 'short_name' => 'ETB'],
            ['image' => $upload],
        );
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, preg_match('#/api/v1/media/([a-f0-9]{64})(?:\?.*)?$#', $payload['logoUrl'], $m));
        $path = '/api/v1/media/'.$m[1];

        $client->request('GET', $path);
        $etag = $client->getResponse()->headers->get('ETag');
        self::assertNotNull($etag);

        $client->request('GET', $path, server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
    }
}
