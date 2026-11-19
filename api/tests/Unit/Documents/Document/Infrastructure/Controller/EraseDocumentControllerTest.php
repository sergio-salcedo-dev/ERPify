<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Controller;

use Erpify\Documents\Document\Application\AppendCustody;
use Erpify\Documents\Document\Application\Erasure\ApplyErasure;
use Erpify\Documents\Document\Application\Erasure\EraseDocument;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Erasure\DocumentErasures;
use Erpify\Documents\Document\Domain\Erasure\ErasureReason;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Repository\DocumentSeriesRepository;
use Erpify\Documents\Document\Infrastructure\Controller\EraseDocumentController;
use Erpify\Documents\Document\Infrastructure\Http\EraseDocumentRequest;
use Erpify\Documents\Document\Infrastructure\Http\UploadResourceMapper;
use Erpify\Iam\Identity\Application\ProveActorPassword;
use Erpify\Iam\Identity\Application\ProveCurrentPassword;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Security\CurrentPasswordProofThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Double\Erasure\FakeErasureLedger;
use Erpify\Tests\Support\ResourceResponderBuilder;
use Erpify\Tests\Unit\Documents\Document\Application\CountingTransactionManager;
use Erpify\Tests\Unit\Documents\Document\Application\InMemoryDocumentCustodyRepository;
use Erpify\Tests\Unit\Documents\Document\Application\InMemoryDocumentRepository;
use Erpify\Tests\Unit\Documents\Document\Application\InMemoryUploadIntentRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedActorContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The erase route's controller: the caller's current password is re-proved, and the shared per-identity budget spent,
 * BEFORE the use case is reached — so a wrong password, an exhausted budget or a caller who is not a user writes
 * nothing to the erasure ledger. The use case, its transaction and the SQL are held by the functional tests; here
 * the only erasure the use case can reach is the one that answers from the row (an already `Erased` document).
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the controller, its proof collaborators and the use case doubles
 */
#[CoversClass(EraseDocumentController::class)]
final class EraseDocumentControllerTest extends TestCase
{
    private const string PASSWORD = 'the-current-password';

    private InMemoryDocumentRepository $documents;

    protected function setUp(): void
    {
        parent::setUp();
        SystemClock::set(FixedClock::at('2026-11-05T12:00:00+00:00'));
        FakeErasureLedger::reset();
        $this->documents = new InMemoryDocumentRepository();
    }

    protected function tearDown(): void
    {
        FakeErasureLedger::reset();
        parent::tearDown();
    }

    #[Test]
    public function aWrongPasswordIsRefusedAndNothingReachesTheLedger(): void
    {
        $document = $this->plant(DocumentState::Ready);

        try {
            $this->controller()($document->documentId(), $this->request('not-it'));
            $this->fail('A wrong current password must be refused.');
        } catch (InvalidCurrentPassword) {
            $this->assertSame([], FakeErasureLedger::attempts(), 'the ledger was written after a failed proof');
        }
    }

    #[Test]
    public function aSpentBudgetRefusesBeforeTheProofAndTheUseCase(): void
    {
        $document = $this->plant(DocumentState::Ready);
        $controller = $this->controller(budget: 1);

        try {
            $controller($document->documentId(), $this->request('spends-it'));
        } catch (InvalidCurrentPassword) {
            // The budget is spent by the attempt, not by its outcome — which is what leaves none for below.
        }

        $this->expectException(RateLimitExceeded::class);

        try {
            $controller($document->documentId(), $this->request(self::PASSWORD));
        } finally {
            $this->assertSame([], FakeErasureLedger::attempts());
        }
    }

    #[Test]
    public function aCallerWhoIsNotAUserIsRefusedBeforeAnythingIsSpent(): void
    {
        $document = $this->plant(DocumentState::Ready);

        $this->expectException(AccessDeniedException::class);

        $this->controller(actor: ActorContext::system())($document->documentId(), $this->request(self::PASSWORD));
    }

    #[Test]
    public function aMalformedIdIsARequestTargetErrorBeforeAnyProof(): void
    {
        $this->expectException(InvalidUuidException::class);

        $this->controller()('not-a-uuid', $this->request('not-it'));
    }

    #[Test]
    public function aRightPasswordReachesTheUseCaseWhichRefusesAnUnknownDocument(): void
    {
        $this->expectException(DocumentNotFound::class);

        $this->controller()(Uuid::generate(), $this->request(self::PASSWORD));
    }

    #[Test]
    public function aRightPasswordOverAnAlreadyErasedDocumentAnswersItsReceiptAndWritesNothing(): void
    {
        $document = $this->plant(DocumentState::Erased);

        $response = $this->controller()($document->documentId(), $this->request(self::PASSWORD));

        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_OK,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );
        $body = \json_decode((string) $response->getContent(), true, 8, JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $data = $body['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame($document->documentId(), $data['id'] ?? null);
        $this->assertSame('erased', $data['state'] ?? null);
        $this->assertSame([], FakeErasureLedger::attempts(), 'a repeat writes no ledger entry');
    }

    private function request(string $password): EraseDocumentRequest
    {
        return new EraseDocumentRequest(ErasureReason::DataSubjectRequest, $password);
    }

    private function plant(DocumentState $state): Document
    {
        $document = Document::receive(Uuid::generate(), Uuid::generate(), Uuid::generate(), \str_repeat('a', 64), 10);
        (new ReflectionProperty(Document::class, 'state'))->setValue($document, $state);
        $this->documents->documents[$document->documentId()] = $document;

        return $document;
    }

    private function controller(int $budget = 5, ?ActorContext $actor = null): EraseDocumentController
    {
        $caller = UserMother::create(password: HashedPassword::fromHash(self::PASSWORD));
        $users = new InMemoryUserRepository($caller);
        $users->seeded[] = $caller;
        $transactions = new CountingTransactionManager();

        $erase = new EraseDocument(
            $this->documents,
            new FakeErasureLedger(),
            new ApplyErasure(
                new InMemoryUploadIntentRepository(),
                $this->documents,
                $this->createStub(DocumentErasures::class),
                new AppendCustody($this->documents, new InMemoryDocumentCustodyRepository(), $transactions),
                $this->createStub(Keystore::class),
                $transactions,
            ),
            new NullLogger(),
            $this->createStub(DocumentSeriesRepository::class),
        );

        return new EraseDocumentController(
            $erase,
            new ProveActorPassword($users, new ProveCurrentPassword()),
            new PasswordHasher(new PasswordHasherFactory([SecurityUser::class => ['algorithm' => 'plaintext']])),
            new CurrentPasswordProofThrottle(
                new RateLimiterFactory(
                    [
                        'id' => 'per_identity',
                        'policy' => 'sliding_window',
                        'limit' => $budget,
                        'interval' => '15 minutes',
                    ],
                    new InMemoryStorage(),
                ),
                new RequestStack(),
            ),
            $this->documents,
            new FixedActorContextFactory($actor ?? ActorContext::forUser(UserMother::DEFAULT_ID)),
            new UploadResourceMapper(),
            ResourceResponderBuilder::wired(),
        );
    }
}
