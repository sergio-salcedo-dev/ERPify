<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Tests\Support\Dig;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two ingest routes as a functional test drives them — the declare, the raw-body PUT, and the reading of
 * what they answered and left in the database. Rows are read through DBAL, never the ORM.
 *
 * The using class owns a `KernelBrowser $client` with reboots disabled, so these requests and the rows they
 * read share one container and one connection.
 */
trait SendsUploadRequests
{
    private const string DECLARE = '/api/v1/documents/uploads';

    private KernelBrowser $client;

    private function declare(int $size, ?string $sha256): string
    {
        $payload = ['size' => $size, 'retentionReason' => 'contract-execution', 'legalBasis' => 'contract'];

        if (null !== $sha256) {
            $payload['sha256'] = $sha256;
        }

        $this->postDeclare($payload);
        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), $this->body());
        $id = Dig::value($this->data(), 'id');
        $this->assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postDeclare(array $payload): void
    {
        $this->client->request(
            Request::METHOD_POST,
            self::DECLARE,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) \json_encode($payload),
        );
    }

    private function put(string $upload, string $bytes, string $name, ?int $contentLength = -1): void
    {
        $server = ['CONTENT_TYPE' => 'application/octet-stream'];

        if (null !== $contentLength) {
            $server['CONTENT_LENGTH'] = (string) (-1 === $contentLength ? \strlen($bytes) : $contentLength);
        }

        $this->client->request(
            Request::METHOD_PUT,
            \sprintf('%s/%s/content?filename=%s', self::DECLARE, $upload, \rawurlencode($name)),
            server: $server,
            content: $bytes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        $decoded = \json_decode($this->body(), true);
        $this->assertIsArray($decoded);
        $data = $decoded['data'] ?? $decoded;
        $this->assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function problemType(): mixed
    {
        $decoded = \json_decode($this->body(), true);

        return \is_array($decoded) ? ($decoded['type'] ?? null) : null;
    }

    private function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function intentState(string $upload): mixed
    {
        return $this->connection()->fetchOne('SELECT state FROM upload_intent WHERE id = :id', ['id' => $upload]);
    }

    private function scopeOf(mixed $documentId): string
    {
        return $this->stringOf('SELECT scope_id FROM document WHERE id = :id', ['id' => $documentId]);
    }

    private function forgetIntentsOf(string $email): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM upload_intent WHERE actor_id IN (SELECT id FROM identity_user WHERE email = :email)',
            ['email' => $email],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function numberFrom(string $sql, array $parameters = []): int
    {
        return Dig::int([$this->connection()->fetchOne($sql, $parameters)], 0);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function stringOf(string $sql, array $parameters = []): string
    {
        return Dig::string([$this->connection()->fetchOne($sql, $parameters)], 0);
    }

    private function connection(): Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getConnection();
    }
}
