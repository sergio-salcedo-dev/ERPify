<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class BankStoredObjectMultipartFunctionalTest extends WebTestCase
{
    private const MIN_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';

    public function testPostMultipartBankWithStoredObjectReturnsUrlAndServesImage(): void
    {
        $client = static::createClient();

        $tmp = tempnam(sys_get_temp_dir(), 'erpify_stored');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, base64_decode(self::MIN_PNG, true));
        $upload = new UploadedFile($tmp, 'extra.png', 'image/png', null, true);

        $client->request(
            'POST',
            '/api/v1/backoffice/banks',
            [
                'name' => 'Stored Object Bank',
                'short_name' => 'SOB',
            ],
            ['stored_object' => $upload],
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Stored Object Bank', $payload['name']);
        self::assertNull($payload['logoUrl'] ?? null);
        self::assertIsString($payload['storedObjectUrl']);
        self::assertSame(1, preg_match('#/api/v1/stored-objects/([a-f0-9]{64})(?:\?.*)?$#', $payload['storedObjectUrl'], $m), $payload['storedObjectUrl']);
        $path = parse_url($payload['storedObjectUrl'], PHP_URL_PATH);
        self::assertIsString($path);

        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('immutable', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertNotNull($client->getResponse()->headers->get('ETag'));
    }
}
