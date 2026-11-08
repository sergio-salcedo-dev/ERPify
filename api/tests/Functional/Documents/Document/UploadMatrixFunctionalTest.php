<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\PurgeUserDocumentReferences;
use Erpify\Documents\Document\Application\Upload\ClaimUpload;
use Erpify\Documents\Document\Application\Upload\DeclareUpload;
use Erpify\Documents\Document\Application\Upload\ExpireUpload;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Documents\Document\Domain\UploadLimits;
use Erpify\Documents\Document\Infrastructure\Controller\DeclareUploadController;
use Erpify\Documents\Document\Infrastructure\Controller\UploadEvidenceController;
use Erpify\Documents\Document\Infrastructure\Security\DeclareUploadThrottle;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Support\Dig;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The rows of the ingest matrix that need a state the HTTP flow passes through in milliseconds — a full cap, a
 * spent budget, a streaming or expired intent, an erased document, an erased actor — planted through DBAL and
 * then driven through the two real routes.
 *
 * Planted rows are removed after each test by the planting trait, and the functional actor's own intents around
 * each test: the suite shares one database, and both caps count rows.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one test per matrix row and state
 * @SuppressWarnings("PHPMD.TooManyMethods") the same, and their planting helpers
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") the same
 */
#[CoversClass(DeclareUploadController::class)]
#[CoversClass(UploadEvidenceController::class)]
#[CoversClass(DeclareUpload::class)]
#[CoversClass(ClaimUpload::class)]
#[CoversClass(DeclareUploadThrottle::class)]
final class UploadMatrixFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;
    use PlantsUploadIntents;
    use ResolvesContainerServices;
    use SendsUploadRequests;

    private const string ERASED_EMAIL = 'functional-uploader-erased@erpify.test';

    /** @var list<string> */
    private array $scopes = [];

    /** @var list<string> intents whose actor an erasure rewrote, so no per-actor cleanup reaches them */
    private array $anonymisedIntents = [];

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

        foreach ($this->anonymisedIntents as $intent) {
            $this->connection()->executeStatement('DELETE FROM upload_intent WHERE id = :id', ['id' => $intent]);
        }

        $this->forgetIntentsOf(self::FUNCTIONAL_USER_EMAIL);
        $this->forgetIntentsOf(self::ERASED_EMAIL);
        parent::tearDown();
    }

    #[Test]
    public function aDeclareOverTheLiveDeclaredCapIsRateLimitedAndCreatesNothing(): void
    {
        $actor = $this->actorIdOf(self::FUNCTIONAL_USER_EMAIL);

        for ($i = 0; $i < UploadLimits::MAX_LIVE_DECLARED_PER_ACTOR; ++$i) {
            $this->plantIntent($actor, UploadIntentState::Declared, claimed: false);
        }

        $keys = $this->numberFrom('SELECT COUNT(*) FROM dek_keystore');

        $this->postDeclare(['size' => 10, 'retentionReason' => 'contract-execution', 'legalBasis' => 'contract']);

        $this->assertSame(
            Response::HTTP_TOO_MANY_REQUESTS,
            $this->client->getResponse()->getStatusCode(),
            $this->body(),
        );
        $this->assertSame('rate-limited', $this->problemType());
        $this->assertFalse($this->client->getResponse()->headers->has('Retry-After'), 'a cap carries no Retry-After');
        $this->assertSame(UploadLimits::MAX_LIVE_DECLARED_PER_ACTOR, $this->intentCountOf($actor), 'no intent row');
        $this->assertSame($keys, $this->numberFrom('SELECT COUNT(*) FROM dek_keystore'), 'no keystore row');
    }

    #[Test]
    public function aSpentDeclareBudgetIsRateLimitedWithRetryAfterAndCreatesNothing(): void
    {
        $actor = $this->actorIdOf(self::FUNCTIONAL_USER_EMAIL);
        $this->spendTheDeclareBudgetOf($actor);
        $keys = $this->numberFrom('SELECT COUNT(*) FROM dek_keystore');

        $this->postDeclare(['size' => 10, 'retentionReason' => 'contract-execution', 'legalBasis' => 'contract']);

        $this->assertSame(
            Response::HTTP_TOO_MANY_REQUESTS,
            $this->client->getResponse()->getStatusCode(),
            $this->body(),
        );
        $this->assertSame('rate-limited', $this->problemType());
        $this->assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        $this->assertSame(0, $this->intentCountOf($actor), 'no intent row');
        $this->assertSame($keys, $this->numberFrom('SELECT COUNT(*) FROM dek_keystore'), 'no keystore row');
    }

    #[Test]
    public function aReplayOfAStreamingUploadConflictsWithRetryAfter(): void
    {
        $payload = $this->keyedPayload();
        $upload = $this->declaredWith($payload);
        $this->streamLive($upload);

        $this->postDeclare($payload);

        $this->assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode(), $this->body());
        $this->assertSame('upload-in-progress', $this->problemType());
        $retryAfter = (int) $this->client->getResponse()->headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(UploadLimits::LEASE_SECONDS, $retryAfter);
        $this->assertSame('streaming', $this->intentState($upload));
        $this->assertSame(1, $this->intentCountOf($this->actorIdOf(self::FUNCTIONAL_USER_EMAIL)));
    }

    /**
     * An expired intent's key is released, so a replay is a first declare: a fresh intent holding the key,
     * and the expired one left to the reaper without it — whether something had stamped it `Expired` already or
     * only time had expired it.
     */
    #[Test]
    #[DataProvider('provideAReplayOfAnExpiredUploadIsAnsweredAsIfTheKeyWereNewCases')]
    public function aReplayOfAnExpiredUploadIsAnsweredAsIfTheKeyWereNew(?string $expiry): void
    {
        $payload = $this->keyedPayload();
        $upload = $this->declaredWith($payload);
        $this->expire($upload, $expiry);

        $this->postDeclare($payload);

        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), $this->body());
        $fresh = Dig::string($this->data(), 'id');
        $this->assertNotSame($upload, $fresh);
        $this->assertSame('declared', $this->intentState($fresh));
        $this->assertNotNull($this->keyHashOf($fresh), 'the fresh intent holds the key');
        $this->assertSame('expired', $this->intentState($upload));
        $this->assertNull($this->keyHashOf($upload), 'the expired intent no longer holds it');
    }

    /**
     * The SQL that expires the intent by time, or null for the transition an aborted upload runs.
     *
     * @return iterable<string, array{?string}>
     */
    public static function provideAReplayOfAnExpiredUploadIsAnsweredAsIfTheKeyWereNewCases(): iterable
    {
        yield 'stamped expired by an abort' => [null];
        yield 'declared past its ttl' => [
            "UPDATE upload_intent SET ttl_expires_at = NOW() - INTERVAL '1 second' WHERE id = :id",
        ];
        yield 'streaming past its lease' => [
            "UPDATE upload_intent SET state = 'streaming', claimed_at = NOW() - INTERVAL '2 minutes', "
            . "lease_expires_at = NOW() - INTERVAL '1 second', upload_deadline_at = NOW() + INTERVAL '10 minutes' "
            . 'WHERE id = :id',
        ];
    }

    #[Test]
    #[DataProvider('provideAnErasedOriginalCases')]
    public function aReplayOfAnErasedDocumentAnswersItsTombstone(bool $intentPruned): void
    {
        $payload = $this->keyedPayload();
        $document = $this->filedThenErased($payload, $intentPruned);
        $actor = $this->actorIdOf(self::FUNCTIONAL_USER_EMAIL);
        $intents = $this->intentCountOf($actor);

        $this->postDeclare($payload);

        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), $this->body());
        $this->assertSame($document, Dig::value($this->data(), 'documentId'));
        $this->assertSame('erased', Dig::value($this->data(), 'state'));
        $this->assertSame($intents, $this->intentCountOf($actor), 'a replay revives nothing');
    }

    #[Test]
    #[DataProvider('provideAnErasedOriginalCases')]
    public function aParameterMismatchAgainstAnErasedDocumentConflicts(bool $intentPruned): void
    {
        $payload = $this->keyedPayload();
        $this->filedThenErased($payload, $intentPruned);
        $actor = $this->actorIdOf(self::FUNCTIONAL_USER_EMAIL);
        $intents = $this->intentCountOf($actor);

        $this->postDeclare(['legalBasis' => 'legal-obligation'] + $payload);

        $this->assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode(), $this->body());
        $this->assertSame('idempotency-key-conflict', $this->problemType());
        $this->assertSame($intents, $this->intentCountOf($actor));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideAnErasedOriginalCases(): iterable
    {
        yield 'its intent still retained' => [false];
        yield 'its intent already pruned' => [true];
    }

    #[Test]
    public function afterTheActorsOwnErasureTheSameKeyIsNoLongerMatched(): void
    {
        $this->authenticateAs($this->client, self::ERASED_EMAIL, ['EDITOR']);
        $actor = $this->actorIdOf(self::ERASED_EMAIL);
        $payload = $this->keyedPayload();
        $upload = $this->declaredWith($payload);
        $this->anonymisedIntents[] = $upload;
        $this->put($upload, 'x', 'x.txt');
        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), $this->body());
        $document = Dig::string($this->data(), 'id');
        $this->scopes[] = $this->scopeOf($document);

        $this->service(TransactionManager::class)->transactional(
            fn (): int => $this->service(PurgeUserDocumentReferences::class)->eraseReferencesTo($actor),
        );

        $this->assertNull($this->keyHashOf($upload), 'the purge nulled the intent hash');
        $this->assertNull(
            $this->connection()->fetchOne('SELECT idempotency_key_hash FROM document WHERE id = :id', [
                'id' => $document,
            ]),
            'the purge nulled the document hash',
        );

        $this->postDeclare($payload);

        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), $this->body());
        $fresh = Dig::string($this->data(), 'id');
        $this->assertNotSame($upload, $fresh);
        $this->assertNull(Dig::value($this->data(), 'documentId'), "the erased actor's document is not answered");
    }

    /**
     * `review_at` carries no zone, so an offset kept to the column would be written as a wall clock off by its
     * offset, and an identical replay would then fail to match it.
     */
    #[Test]
    public function aReviewDateWithAnOffsetIsStoredAsItsInstantAndAnIdenticalReplayMatches(): void
    {
        $payload = ['reviewAt' => '2027-03-01T10:00:00+02:00'] + $this->keyedPayload();
        $upload = $this->declaredWith($payload);

        $this->assertSame(
            '2027-03-01 08:00:00',
            $this->connection()->fetchOne('SELECT review_at FROM upload_intent WHERE id = :id', ['id' => $upload]),
        );

        $this->postDeclare($payload);

        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), $this->body());
        $this->assertSame($upload, Dig::value($this->data(), 'id'));

        $this->postDeclare(['reviewAt' => '2027-03-01T08:00:00Z'] + $payload);

        $this->assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            'the same instant spelt in UTC is the same declaration',
        );
    }

    #[Test]
    public function aMalformedUploadIdIsARequestTargetErrorBeforeAnyLookup(): void
    {
        $this->put('not-a-uuid', '0123456789', 'a.txt');

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode(), $this->body());
        $this->assertSame('invalid-uuid', $this->problemType());
    }

    #[Test]
    public function aPutOnAStreamingUploadConflictsAndTouchesNothing(): void
    {
        $upload = $this->declare(10, null);
        $this->streamLive($upload);
        $before = $this->intentRow($upload);
        $scope = Dig::string($before, 'scope_id');
        $this->scopes[] = $scope;

        $this->put($upload, '0123456789', 'a.txt');

        $this->assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode(), $this->body());
        $this->assertSame('upload-in-progress', $this->problemType());
        $this->assertGreaterThanOrEqual(1, (int) $this->client->getResponse()->headers->get('Retry-After'));
        $this->assertSame($before, $this->intentRow($upload), 'the intent row is untouched');
        $this->assertNothingStoredFor($scope);
    }

    #[Test]
    public function aClaimOverTheActorsStreamingCapIsRateLimitedWithoutRetryAfter(): void
    {
        $actor = $this->actorIdOf(self::FUNCTIONAL_USER_EMAIL);

        for ($i = 0; $i < UploadLimits::MAX_STREAMING_PER_ACTOR; ++$i) {
            $this->plantIntent($actor, UploadIntentState::Streaming);
        }

        $this->assertSame(
            UploadLimits::MAX_STREAMING_PER_ACTOR,
            $this->liveStreamingCount(),
            'only the planted streams are live, so the global cap is not what refuses',
        );

        $this->assertClaimIsRefusedForCapacity();
    }

    #[Test]
    public function aClaimOverTheGlobalStreamingCapIsRateLimitedWithoutRetryAfter(): void
    {
        for ($i = 0; $i < UploadLimits::MAX_STREAMING_GLOBAL; ++$i) {
            $this->plantIntent(Uuid::generate(), UploadIntentState::Streaming);
        }

        $this->assertSame(
            0,
            $this->numberFrom(
                "SELECT COUNT(*) FROM upload_intent WHERE state = 'streaming' AND actor_id = :actor",
                ['actor' => $this->actorIdOf(self::FUNCTIONAL_USER_EMAIL)],
            ),
            'the caller streams nothing, so its own cap is not what refuses',
        );

        $this->assertClaimIsRefusedForCapacity();
    }

    private function assertClaimIsRefusedForCapacity(): void
    {
        $upload = $this->declare(10, null);
        $scope = $this->stringOf('SELECT scope_id FROM upload_intent WHERE id = :id', ['id' => $upload]);
        $this->scopes[] = $scope;

        $this->put($upload, '0123456789', 'a.txt');

        $this->assertSame(
            Response::HTTP_TOO_MANY_REQUESTS,
            $this->client->getResponse()->getStatusCode(),
            $this->body(),
        );
        $this->assertSame('rate-limited', $this->problemType());
        $this->assertFalse($this->client->getResponse()->headers->has('Retry-After'), 'a cap carries no Retry-After');
        $this->assertSame('declared', $this->intentState($upload));
        $this->assertNull($this->connection()->fetchOne(
            'SELECT claimed_at FROM upload_intent WHERE id = :id',
            ['id' => $upload],
        ));
        $this->assertNothingStoredFor($scope);
    }

    private function assertNothingStoredFor(string $scope): void
    {
        $this->assertSame(0, $this->numberFrom(
            'SELECT COUNT(*) FROM dek_keystore WHERE encryption_scope_id = :scope',
            ['scope' => EncryptionScopeId::forDocument($scope)->toString()],
        ), 'no key minted');
        $this->assertFalse($this->service(DocumentStorage::class)->has(StorageArea::Staging, $scope));
        $this->assertSame(0, $this->numberFrom('SELECT COUNT(*) FROM document WHERE scope_id = :s', ['s' => $scope]));
    }

    /**
     * Files a document under the payload's key through both routes, then puts it where a later erasure of the
     * document leaves it: `Erased`, its sealed name gone, every field a replay compares kept.
     *
     * @param array<string, mixed> $payload
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag") the one fact the two cases differ by
     */
    private function filedThenErased(array $payload, bool $intentPruned): string
    {
        $upload = $this->declaredWith($payload);
        $this->put($upload, 'x', 'x.txt');
        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), $this->body());
        $document = Dig::string($this->data(), 'id');
        $this->scopes[] = $this->scopeOf($document);

        $this->connection()->executeStatement(
            "UPDATE document SET state = 'erased', filename_ciphertext = NULL WHERE id = :id",
            ['id' => $document],
        );

        if ($intentPruned) {
            $this->connection()->executeStatement('DELETE FROM upload_intent WHERE id = :id', ['id' => $upload]);
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function declaredWith(array $payload): string
    {
        $this->postDeclare($payload);
        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), $this->body());

        return Dig::string($this->data(), 'id');
    }

    /**
     * @return array<string, mixed>
     */
    private function keyedPayload(): array
    {
        return [
            'size' => 1,
            'sha256' => \hash('sha256', 'x'),
            'idempotencyKey' => 'matrix-' . \bin2hex(\random_bytes(6)),
            'retentionReason' => 'technical-file',
            'legalBasis' => 'legitimate-interests',
        ];
    }

    /**
     * Expires the intent by the given SQL, or — with none — through the transition an aborted upload runs, which
     * only a streaming intent takes.
     */
    private function expire(string $upload, ?string $expiry): void
    {
        if (null !== $expiry) {
            $this->connection()->executeStatement($expiry, ['id' => $upload]);

            return;
        }

        $scope = $this->stringOf('SELECT scope_id FROM upload_intent WHERE id = :id', ['id' => $upload]);
        $this->streamLive($upload);
        $this->service(ExpireUpload::class)->expire($upload, $scope);
    }

    /**
     * Moves a declared intent to a live `Streaming` one, as another request's claim would have.
     */
    private function streamLive(string $upload): void
    {
        $this->connection()->executeStatement(
            "UPDATE upload_intent SET state = 'streaming', claimed_at = NOW(), "
            . "lease_expires_at = NOW() + INTERVAL '60 seconds', upload_deadline_at = NOW() + INTERVAL '10 minutes' "
            . 'WHERE id = :id',
            ['id' => $upload],
        );
    }

    /**
     * Spends the actor's whole declare budget through the throttle the route uses, so the key is derived the
     * way the route derives it. The test cache pool is in memory and reset when a request terminates, so the
     * spend reaches exactly the next request.
     */
    private function spendTheDeclareBudgetOf(string $actor): void
    {
        $throttle = $this->service(DeclareUploadThrottle::class);

        for ($spent = 0; $spent < 10_000; ++$spent) {
            try {
                $throttle->ensureWithinBudget($actor);
            } catch (RateLimitExceeded) {
                return;
            }
        }

        $this->fail('The declare budget never ran out.');
    }

    /**
     * @return array<string, mixed>
     */
    private function intentRow(string $upload): array
    {
        $row = $this->connection()->fetchAssociative('SELECT * FROM upload_intent WHERE id = :id', ['id' => $upload]);
        $this->assertIsArray($row);

        return $row;
    }

    private function keyHashOf(string $upload): mixed
    {
        return $this->connection()->fetchOne('SELECT key_hash FROM upload_intent WHERE id = :id', ['id' => $upload]);
    }

    private function intentCountOf(string $actor): int
    {
        return $this->numberFrom('SELECT COUNT(*) FROM upload_intent WHERE actor_id = :actor', ['actor' => $actor]);
    }

    private function liveStreamingCount(): int
    {
        return $this->numberFrom(
            "SELECT COUNT(*) FROM upload_intent WHERE state = 'streaming' "
            . 'AND lease_expires_at > NOW() AND upload_deadline_at > NOW()',
        );
    }

    private function actorIdOf(string $email): string
    {
        return $this->stringOf('SELECT id FROM identity_user WHERE email = :email', ['email' => $email]);
    }
}
