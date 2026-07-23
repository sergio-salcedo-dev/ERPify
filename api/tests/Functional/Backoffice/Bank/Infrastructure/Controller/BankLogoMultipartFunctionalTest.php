<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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
     * Both the file part and the scalars are mapped from the same payload, so the upload survives strict
     * mapping only because `CreateBankRequest` declares `image`. A surplus *scalar* is declared nowhere, so
     * `form` is closed on exactly the same terms as `json`, and an HTML form posting its submit button is
     * refused rather than silently trimmed. Exactly one violation: the accepted part must not add a second.
     */
    public function testMultipartRejectsASurplusFormFieldWhileTheDeclaredFilePartIsAccepted(): void
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

    /**
     * A file part the payload does not declare is surplus on the same terms as a surplus scalar. Naming the
     * offending part back to the caller is what separates "we ignored your upload" from an answer they can act
     * on — the part name is their own input, so echoing it discloses nothing.
     */
    public function testMultipartRejectsAFilePartThePayloadDoesNotDeclare(): void
    {
        $kernelBrowser = self::createClient();
        $this->authenticateClient($kernelBrowser);

        $suffix = \bin2hex(\random_bytes(4));

        $kernelBrowser->request(
            Request::METHOD_POST,
            '/api/v1/backoffice/banks',
            [
                'name' => 'Undeclared Part Bank ' . $suffix,
                'shortName' => 'UPB' . $suffix,
            ],
            ['logo' => $this->pngUpload()],
        );

        self::assertResponseStatusCodeSame(422);
        $violations = $this->violationsFrom($kernelBrowser);
        $this->assertCount(1, $violations);
        $this->assertSame('logo', $violations[0]['field']);
    }

    /**
     * The payload resolver lets a file part overwrite a scalar of the same name, so the part name is an input
     * the caller controls all the way into the mapped member. Pinned here because it is the shape an attacker
     * would reach for: a part named after a declared member, or after the surplus scalar it wants excused.
     * Neither is excused — the scalar is still reported, and no bank is created.
     */
    public function testMultipartDoesNotLetAFilePartNameLaunderASurplusScalar(): void
    {
        $kernelBrowser = self::createClient();
        $this->authenticateClient($kernelBrowser);

        $suffix = \bin2hex(\random_bytes(4));

        $kernelBrowser->request(
            Request::METHOD_POST,
            '/api/v1/backoffice/banks',
            [
                'name' => 'Laundering Bank ' . $suffix,
                'shortName' => 'LDB' . $suffix,
                'submit' => 'Save',
            ],
            ['submit' => $this->pngUpload()],
        );

        self::assertResponseStatusCodeSame(422);
        $violations = $this->violationsFrom($kernelBrowser);
        $this->assertCount(1, $violations);
        $this->assertSame('submit', $violations[0]['field']);
    }

    private function pngUpload(): UploadedFile
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'erpify_logo');
        $this->assertNotFalse($tmp);
        \file_put_contents($tmp, \base64_decode(self::MIN_PNG, true));

        return new UploadedFile($tmp, 'logo.png', self::PNG_MIME, null, true);
    }

    /**
     * @return list<array{field: string, message: string, code: string}>
     */
    private function violationsFrom(KernelBrowser $kernelBrowser): array
    {
        $body = \json_decode((string) $kernelBrowser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('type', $body);
        $this->assertSame('validation-failed', $body['type']);
        $this->assertArrayHasKey('violations', $body);
        $this->assertIsArray($body['violations']);

        /** @phpstan-var list<array{field: string, message: string, code: string}> $violations */
        $violations = \array_values($body['violations']);

        return $violations;
    }
}
