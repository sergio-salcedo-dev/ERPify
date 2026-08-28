<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /banks` accepts a JSON body and nothing else. A form-encoded or multipart request is refused at
 * the argument resolver with 415 before any handler runs, so the endpoint has no path along which a
 * client-supplied file part reaches the application. Pinning it here keeps that a tested property rather
 * than an emergent one: the resolver's accepted-format list is a single attribute argument, and widening
 * it back to `form` would silently re-open a file-bearing request surface.
 *
 * @internal
 */
#[CoversNothing]
final class BankCreateAcceptsJsonOnlyFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    private const string ENDPOINT = '/api/v1/backoffice/banks';

    private KernelBrowser $client;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();

        // Isolation: no DAMA rollback in this suite, so start from a known-empty table.
        $this->entityManager()->getConnection()->executeStatement(
            'TRUNCATE bank_account, bank RESTART IDENTITY CASCADE',
        );

        $this->authenticateClient($this->client);
    }

    public function testAMultipartRequestCarryingAFileIsRefusedBeforeAnyHandlerRuns(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'bank-upload-probe');
        $this->assertIsString($file);
        \file_put_contents($file, 'probe-bytes');

        $this->client->request(
            Request::METHOD_POST,
            self::ENDPOINT,
            ['name' => 'Multipart Probe', 'shortName' => 'MPP'],
            ['image' => new UploadedFile($file, 'probe.png', 'image/png', test: true)],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        $this->assertSame(
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );
        $this->assertSame(0, $this->countBanks(), 'the refused request creates nothing');

        // The probe outlives the assertion otherwise: `UploadedFile` in test mode neither moves nor removes
        // it, and a functional suite that leaves a file per run in the system temp directory is a leak whose
        // only symptom is elsewhere.
        \unlink($file);
    }

    public function testAFormEncodedBodyIsRefusedEvenWithoutAFilePart(): void
    {
        $this->client->request(
            Request::METHOD_POST,
            self::ENDPOINT,
            ['name' => 'Form Probe', 'shortName' => 'FMP'],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        $this->assertSame(
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );
        $this->assertSame(0, $this->countBanks());
        $this->assertSame(
            'unsupported-media-type',
            $this->problemType(),
            'the refusal carries its own wire type, so a client can answer it by resending as JSON',
        );
    }

    public function testAJsonBodyIsAccepted(): void
    {
        $this->client->request(
            Request::METHOD_POST,
            self::ENDPOINT,
            server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            content: '{"name":"Json Probe","shortName":"JSP"}',
        );

        $this->assertSame(
            Response::HTTP_CREATED,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );
        $this->assertSame(1, $this->countBanks());
    }

    /**
     * The `type` member of the RFC 9457 body, which is what a client routes on. Read here rather than left
     * to the status code alone: 415 and the generic bucket share a status the moment the bridge map loses
     * its entry, and only the body says which of the two the caller was handed.
     */
    private function problemType(): string
    {
        $body = \json_decode(
            (string) $this->client->getResponse()->getContent(),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($body);
        $this->assertArrayHasKey('type', $body);
        $this->assertIsString($body['type']);

        return $body['type'];
    }

    private function countBanks(): int
    {
        $count = $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM bank');
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
