<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Documents\Document\Application\Upload\CommitUpload;
use Erpify\Documents\Document\Application\Upload\ReapUploadIntents;
use Erpify\Documents\Document\Application\Upload\UploadEvidence;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Documents\Document\Infrastructure\Controller\DeclareUploadController;
use Erpify\Documents\Document\Infrastructure\Controller\UploadEvidenceController;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Application\StreamEnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Erpify\Tests\Functional\EnumeratesChannelLoggers;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Support\Dig;
use Monolog\Handler\TestHandler;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two ingest routes end to end through the kernel, against real Postgres and the real document volume: the
 * declare, the raw-body PUT, and what each refusal leaves behind. Bytes are read back through the storage port
 * and the decryptor, because ingest exposes no read route.
 *
 * Rows are read through DBAL, never the ORM, and every intent of the functional actor is removed around each test:
 * the suite shares one database, and the per-actor cap on live declared intents would otherwise carry over.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.TooManyMethods") one test per row of the ingest matrix, and their request helpers
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") the same
 */
#[CoversClass(DeclareUploadController::class)]
#[CoversClass(UploadEvidenceController::class)]
#[CoversClass(UploadEvidence::class)]
final class UploadEvidenceFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;
    use EnumeratesChannelLoggers;
    use ResolvesContainerServices;

    private const string DECLARE = '/api/v1/documents/uploads';

    private const string OTHER_EMAIL = 'functional-uploader-two@erpify.test';

    private KernelBrowser $client;

    /** @var list<string> */
    private array $scopes = [];

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->authenticateClient($this->client);
        $this->forgetIntentsOf(self::FUNCTIONAL_USER_EMAIL);
    }

    protected function tearDown(): void
    {
        $storage = $this->service(DocumentStorage::class);

        foreach ($this->scopes as $scope) {
            $storage->delete(StorageArea::Staging, $scope);
            $storage->delete(StorageArea::Final, $scope);
        }

        $this->forgetIntentsOf(self::FUNCTIONAL_USER_EMAIL);
        $this->forgetIntentsOf(self::OTHER_EMAIL);
        parent::tearDown();
    }

    #[Test]
    public function aDeclaredUploadIsFiledByItsPutAndReadsBackByteIdentical(): void
    {
        $bytes = \random_bytes(200_000);
        $name = 'Acta de replanteo — obra 12 (firmada).pdf';

        $upload = $this->declare(\strlen($bytes), \hash('sha256', $bytes));
        $this->put($upload, $bytes, $name);

        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), $this->body());
        $receipt = $this->data();
        $this->assertSame('received', Dig::value($receipt, 'state'));
        $this->assertSame(\hash('sha256', $bytes), Dig::value($receipt, 'digest'));
        $this->assertSame(\strlen($bytes), Dig::value($receipt, 'sizeBytes'));

        $row = $this->connection()->fetchAssociative(
            'SELECT * FROM document WHERE id = :id',
            ['id' => Dig::value($receipt, 'id')],
        );
        $this->assertIsArray($row);
        $scope = Dig::string($row, 'scope_id');
        $this->scopes[] = $scope;
        $this->assertNotNull(Dig::value($row, 'promoted_at'), 'the writer promoted its own object');
        $this->assertSame(
            1,
            $this->numberFrom('SELECT COUNT(*) FROM document_custody WHERE document_id = :id AND seq = 1', [
                'id' => Dig::value($receipt, 'id'),
            ]),
        );

        $intent = $this->connection()->fetchAssociative(
            'SELECT * FROM upload_intent WHERE id = :id',
            ['id' => $upload],
        );
        $this->assertIsArray($intent);
        $this->assertSame('committed', Dig::value($intent, 'state'));
        $this->assertSame(Dig::value($receipt, 'id'), Dig::value($intent, 'document_id'));
        $this->assertStringNotContainsString(
            $name,
            (string) \json_encode($intent),
            'the intent row never holds the name',
        );
        $this->assertStringNotContainsString(
            $name,
            (string) \json_encode($row),
            'the document row holds it sealed only',
        );

        $this->assertSame($bytes, $this->unsealFinal($scope));
        $this->assertSame(
            $name,
            $this->service(EnvelopeEncryptor::class)->decrypt(
                EncryptionScopeId::forDocument($scope),
                Dig::string($row, 'filename_ciphertext'),
                CommitUpload::filenameAssociatedData($scope),
            ),
        );
        $this->assertFalse($this->service(DocumentStorage::class)->has(StorageArea::Staging, $scope));
    }

    #[Test]
    public function aPutOnACommittedUploadAnswersItsDocumentAndIgnoresTheName(): void
    {
        $bytes = \random_bytes(1000);
        $upload = $this->declare(\strlen($bytes), null);
        $this->put($upload, $bytes, 'first.bin');
        $first = $this->data();
        $this->scopes[] = $this->scopeOf(Dig::value($first, 'id'));

        $this->put($upload, $bytes, "\u{202E}not even valid");

        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), $this->body());
        $this->assertSame(Dig::value($first, 'id'), Dig::value($this->data(), 'id'));
    }

    #[Test]
    #[DataProvider('provideAnInvalidNameIsRefusedUnquotedAndLeavesTheUploadDeclaredCases')]
    public function anInvalidNameIsRefusedUnquotedAndLeavesTheUploadDeclared(string $name): void
    {
        $sinks = $this->attachLogSinks();
        $bytes = \random_bytes(64);
        $upload = $this->declare(\strlen($bytes), null);

        $this->put($upload, $bytes, $name);

        $this->assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->client->getResponse()->getStatusCode(),
            $this->body(),
        );
        $this->assertSame('invalid-filename', $this->problemType());
        $sentinel = \rawurlencode($name);
        $this->assertStringNotContainsString($name, $this->body());
        $this->assertStringNotContainsString($sentinel, $this->body());

        foreach ($sinks as $sink) {
            foreach ($sink->getRecords() as $record) {
                $serialised = (string) \json_encode([$record->message, $record->context, $record->extra]);
                $this->assertStringNotContainsString($name, $serialised, 'a log record quoted the name');
                $this->assertStringNotContainsString($sentinel, $serialised, 'a log record quoted the encoded name');
            }
        }

        $this->assertSame('declared', $this->intentState($upload));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnInvalidNameIsRefusedUnquotedAndLeavesTheUploadDeclaredCases(): iterable
    {
        yield 'a bidi override' => ["invoice\u{202E}fdp.exe"];
        yield '256 bytes' => [\str_repeat('a', 252) . '.pdf'];
        yield 'a control character' => ["report\x01.pdf"];
    }

    #[Test]
    public function aPutWithoutContentLengthIsRefusedAndLeavesTheUploadDeclared(): void
    {
        $upload = $this->declare(10, null);

        $this->put($upload, '0123456789', 'a.txt', contentLength: null);

        $this->assertSame(Response::HTTP_LENGTH_REQUIRED, $this->client->getResponse()->getStatusCode(), $this->body());
        $this->assertSame('length-required', $this->problemType());
        $this->assertSame('declared', $this->intentState($upload));
    }

    #[Test]
    public function aPutWhoseContentLengthIsNotTheDeclaredSizeIsRefusedAndLeavesTheUploadDeclared(): void
    {
        $upload = $this->declare(10, null);

        $this->put($upload, '012345678', 'a.txt', contentLength: 9);

        $this->assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->client->getResponse()->getStatusCode(),
            $this->body(),
        );
        $this->assertSame('content-length-mismatch', $this->problemType());
        $this->assertSame('declared', $this->intentState($upload));
    }

    #[Test]
    public function anotherActorsUploadIsGoneAsIfItDidNotExist(): void
    {
        $upload = $this->declare(10, null);
        $this->authenticateAs($this->client, self::OTHER_EMAIL, ['EDITOR']);

        $this->put($upload, '0123456789', 'a.txt');
        $foreign = [$this->client->getResponse()->getStatusCode(), $this->problemType()];

        $this->put('0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c', '0123456789', 'a.txt');
        $absent = [$this->client->getResponse()->getStatusCode(), $this->problemType()];

        $this->assertSame([410, 'gone'], $foreign);
        $this->assertSame($foreign, $absent);
        $this->assertSame('declared', $this->intentState($upload));
    }

    #[Test]
    public function aBodyWhoseDigestDiffersAbortsAndTheReaperTombstonesItsScope(): void
    {
        $bytes = \random_bytes(5000);
        $upload = $this->declare(\strlen($bytes), \hash('sha256', 'something else'));
        $scope = $this->stringOf('SELECT scope_id FROM upload_intent WHERE id = :id', ['id' => $upload]);
        $this->scopes[] = $scope;

        $this->put($upload, $bytes, 'a.bin');

        $this->assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->client->getResponse()->getStatusCode(),
            $this->body(),
        );
        $this->assertSame('aborted-upload', $this->problemType());
        $this->assertSame('expired', $this->intentState($upload));
        $this->assertNull($this->connection()->fetchOne(
            'SELECT key_hash FROM upload_intent WHERE id = :id',
            ['id' => $upload],
        ));
        $this->assertFalse($this->service(DocumentStorage::class)->has(StorageArea::Staging, $scope));
        $this->assertSame(0, $this->numberFrom('SELECT COUNT(*) FROM document WHERE scope_id = :s', ['s' => $scope]));

        $this->assertTrue($this->service(ReapUploadIntents::class)->reapOne($upload));

        $this->assertFalse($this->connection()->fetchOne(
            'SELECT 1 FROM upload_intent WHERE id = :id',
            ['id' => $upload],
        ));
        $this->assertSame(
            'aborted-upload',
            $this->connection()->fetchOne('SELECT destroy_reason FROM dek_keystore WHERE encryption_scope_id = :s', [
                's' => EncryptionScopeId::forDocument($scope)->toString(),
            ]),
        );
    }

    #[Test]
    public function aShortBodyAbortsTheUpload(): void
    {
        $upload = $this->declare(10, null);
        $this->scopes[] = $this->stringOf('SELECT scope_id FROM upload_intent WHERE id = :id', ['id' => $upload]);

        // The transport cut the body: the declared length arrived, fewer bytes did.
        $this->put($upload, '01234', 'a.txt', contentLength: 10);

        $this->assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->client->getResponse()->getStatusCode(),
            $this->body(),
        );
        $this->assertSame('aborted-upload', $this->problemType());
        $this->assertSame('expired', $this->intentState($upload));
    }

    #[Test]
    #[DataProvider('provideADeclaredSizeOutsideTheRangeCreatesNothingCases')]
    public function aDeclaredSizeOutsideTheRangeCreatesNothing(int $size): void
    {
        $before = $this->numberFrom('SELECT COUNT(*) FROM upload_intent');
        $keys = $this->numberFrom('SELECT COUNT(*) FROM dek_keystore');

        $this->postDeclare(['size' => $size, 'retentionReason' => 'contract-execution', 'legalBasis' => 'contract']);

        $this->assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->client->getResponse()->getStatusCode(),
            $this->body(),
        );
        $this->assertSame($before, $this->numberFrom('SELECT COUNT(*) FROM upload_intent'));
        $this->assertSame($keys, $this->numberFrom('SELECT COUNT(*) FROM dek_keystore'));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideADeclaredSizeOutsideTheRangeCreatesNothingCases(): iterable
    {
        yield 'zero' => [0];
        yield 'one byte past the cap' => [104_857_601];
    }

    #[Test]
    public function exactlyTheCapIsAccepted(): void
    {
        $this->postDeclare([
            'size' => 104_857_600,
            'retentionReason' => 'contract-execution',
            'legalBasis' => 'contract',
        ]);

        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), $this->body());
    }

    #[Test]
    public function aReplayAnswersTheSameUploadAndADifferentParameterConflicts(): void
    {
        $sha = \hash('sha256', 'x');
        $payload = [
            'size' => 1,
            'sha256' => $sha,
            'idempotencyKey' => 'replay-' . \bin2hex(\random_bytes(4)),
            'retentionReason' => 'technical-file',
            'legalBasis' => 'legitimate-interests',
        ];

        $this->postDeclare($payload);
        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), $this->body());
        $first = Dig::string($this->data(), 'id');

        $this->postDeclare($payload);
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), $this->body());
        $this->assertSame($first, Dig::value($this->data(), 'id'));

        $this->postDeclare(['legalBasis' => 'contract'] + $payload);
        $this->assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode(), $this->body());
        $this->assertSame('idempotency-key-conflict', $this->problemType());

        $this->put($first, 'x', 'x.txt');
        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), $this->body());
        $documentId = Dig::value($this->data(), 'id');
        $this->scopes[] = $this->scopeOf($documentId);

        $this->postDeclare($payload);
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), $this->body());
        $this->assertSame($documentId, Dig::value($this->data(), 'documentId'));
    }

    #[Test]
    public function aKeyWithoutADigestIsRefusedAtTheEdge(): void
    {
        $this->postDeclare([
            'size' => 1,
            'idempotencyKey' => 'k',
            'retentionReason' => 'technical-file',
            'legalBasis' => 'contract',
        ]);

        $this->assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->client->getResponse()->getStatusCode(),
            $this->body(),
        );
    }

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

    private function unsealFinal(string $scope): string
    {
        $sealed = $this->service(DocumentStorage::class)->open($scope);
        $plaintext = \fopen('php://memory', 'w+');
        $this->assertNotFalse($plaintext);

        $this->service(StreamEnvelopeEncryptor::class)->unseal(
            EncryptionScopeId::forDocument($scope),
            $sealed,
            $plaintext,
            UploadEvidence::ORIGINAL_ASSOCIATED_DATA,
        );
        \fclose($sealed);
        \rewind($plaintext);

        return (string) \stream_get_contents($plaintext);
    }

    /**
     * @return list<TestHandler>
     */
    private function attachLogSinks(): array
    {
        $sinks = [];

        foreach ($this->channelLoggers() as $logger) {
            $sink = new TestHandler();
            $logger->pushHandler($sink);
            $sinks[] = $sink;
        }

        return $sinks;
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
        return $this->service(EntityManagerInterface::class)->getConnection();
    }
}
