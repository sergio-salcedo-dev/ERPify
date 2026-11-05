<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\ResweepErasedSubjects;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory;
use Erpify\Iam\Identity\Domain\Repository\ErasureResweepRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Invitation\Application\PurgeUserInvitations;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Organization\Membership\Application\PurgeUserMembership;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Application\AuditSubjectTrailErasure;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Application\EventStoreSubjectAnonymiser;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Privacy\Application\PersonReferenceErasure;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Erpify\Tests\Unit\Shared\Privacy\Application\Double\RecordingPersonReferenceErasure;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The identity erasure and its re-sweep reaching the Documents tables through the `Shared\Privacy` seam, wired
 * to the container's real adapters on a real database: a subject who uploaded documents, in every state, is
 * erased and no table of the schema holds their id afterwards.
 *
 * The fixture is COMMITTED, because the erasure owns a real transaction, and removed by hand however the test
 * ends. The audit logger is the one double: a real one would leave `security` rows in a table other suites read,
 * and it sits past the Documents step.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(FulfilIdentityErasure::class)]
#[CoversClass(ResweepErasedSubjects::class)]
final class IdentityErasureReachesDocumentsFunctionalTest extends KernelTestCase
{
    use PlantsDocumentRows;
    use ResolvesContainerServices;

    private string $subjectId;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->emptyDocumentTables();
        $this->subjectId = Uuid::generate();
    }

    protected function tearDown(): void
    {
        $this->emptyDocumentTables();

        if (isset($this->subjectId)) {
            $this->connection()->executeStatement(
                'DELETE FROM identity_erasure_resweep WHERE subject_id = CAST(:id AS UUID)',
                ['id' => $this->subjectId],
            );
            $this->connection()->executeStatement(
                'DELETE FROM identity_user WHERE id = CAST(:id AS UUID)',
                ['id' => $this->subjectId],
            );
        }

        parent::tearDown();
    }

    #[Test]
    public function noTableHoldsTheSubjectAfterTheErasureWhateverTheStateOfTheirDocuments(): void
    {
        $this->seedCommittedSubject();
        $other = Uuid::generate();

        foreach (DocumentState::cases() as $index => $state) {
            $id = $this->plantDocument($this->subjectId, $state, \hash('sha256', 'key ' . $index));
            $this->plantCustody($id, 1, $this->subjectId);
        }

        $theirs = $this->plantDocument($other, DocumentState::Ready);
        $this->plantCustody($theirs, 1, $other);

        $result = $this->chain($this->service(PersonReferenceErasure::class))->execute($this->subjectId);

        $this->assertTrue($result->identityErased, 'the identity was really there to erase');
        $this->assertSame(12, $result->documentReferencesAnonymised, '6 uploaders and 6 custody actors');
        $this->assertSame(
            [],
            $this->tablesHoldingTheSubject(),
            'A table still holds the subject. The only table allowed to is the re-sweep schedule, which is the '
            . "erasure's own instrument and is excluded by name.",
        );
        $this->assertSame(
            12,
            $this->intOf($this->connection()->fetchOne(
                'SELECT (SELECT COUNT(*) FROM document WHERE uploader_id = CAST(:marker AS UUID)) '
                . '+ (SELECT COUNT(*) FROM document_custody WHERE actor_id = CAST(:marker AS UUID))',
                ['marker' => ErasedPersonMarker::UUID],
            )),
        );
        $this->assertSame($other, $this->documentRow($theirs)['uploader_id']);
    }

    #[Test]
    public function aFailureInTheDocumentsStepRollsTheWholeErasureBack(): void
    {
        $this->seedCommittedSubject();
        $document = $this->plantDocument($this->subjectId, DocumentState::Ready, \hash('sha256', 'key'));
        $this->plantCustody($document, 1, $this->subjectId);

        // The real rewrite runs first so the rollback has something to undo: a failure before any write would
        // pass for the wrong reason.
        $real = $this->service(PersonReferenceErasure::class);
        $afterTheRealRewrite = new RecordingPersonReferenceErasure();
        $afterTheRealRewrite->onErase = function () use ($real): never {
            $real->eraseReferencesTo($this->subjectId);

            throw new RuntimeException('the Documents step failed');
        };

        try {
            $this->chain($afterTheRealRewrite)->execute($this->subjectId);
            $this->fail('Expected the Documents failure to abort the erasure.');
        } catch (RuntimeException) {
            $this->assertSame(
                1,
                $this->intOf($this->connection()->fetchOne(
                    'SELECT COUNT(*) FROM identity_user WHERE id = CAST(:id AS UUID)',
                    ['id' => $this->subjectId],
                )),
                'the identity survives: nothing was half-erased',
            );
        }

        $this->assertSame(
            $this->subjectId,
            $this->documentRow($document)['uploader_id'],
            'the rewrite rolled back with it',
        );
        $this->assertSame([$this->subjectId], $this->actorsOf($document));
        $this->assertNotNull($this->documentRow($document)['idempotency_key_hash'], 'and so did the nulled hash');
    }

    #[Test]
    public function aRowCommittedAfterTheErasureIsRewrittenByTheResweep(): void
    {
        $this->seedCommittedSubject();
        $this->chain($this->service(PersonReferenceErasure::class))->execute($this->subjectId);

        // A request in flight when the erasure ran commits a row naming the subject after it.
        $late = $this->plantDocument($this->subjectId, DocumentState::Received, \hash('sha256', 'late'));
        $this->plantCustody($late, 1, $this->subjectId);

        $resweep = new ResweepErasedSubjects(
            $this->service(ErasureResweepRepository::class),
            $this->service(UserRepository::class),
            $this->service(AuditSubjectTrailErasure::class),
            $this->service(EventStoreSubjectAnonymiser::class),
            $this->service(PurgeUserSessions::class),
            new RecordingAuditLogger(),
            $this->service(TransactionManager::class),
            $this->service(Clock::class),
            $this->service(PersonReferenceErasure::class),
        );
        $resweep->resweep();

        $this->assertSame(ErasedPersonMarker::UUID, $this->documentRow($late)['uploader_id']);
        $this->assertNull($this->documentRow($late)['idempotency_key_hash']);
        $this->assertSame([ErasedPersonMarker::UUID], $this->actorsOf($late));
    }

    private function chain(PersonReferenceErasure $documents): FulfilIdentityErasure
    {
        return new FulfilIdentityErasure(
            $this->service(EraseIdentitySubject::class),
            $this->service(AuditSubjectTrailErasure::class),
            $this->service(EventStoreSubjectAnonymiser::class),
            $this->service(ActiveAdministratorDirectory::class),
            $this->service(PurgeUserSessions::class),
            $this->service(PurgeUserMembership::class),
            $this->service(PurgeUserInvitations::class),
            new RecordingAuditLogger(),
            $this->service(ActorContextFactory::class),
            $this->service(TransactionManager::class),
            $this->service(ErasureResweepRepository::class),
            $documents,
        );
    }

    private function seedCommittedSubject(): void
    {
        $user = User::register(
            $this->subjectId,
            'documents-erasure-' . $this->subjectId . '@erpify.test',
            HashedPassword::fromHash('hashed-password-placeholder'),
            Role::AUDIT_READER,
        );
        $user->pullDomainEvents();
        $this->service(UserRepository::class)->save($user);
        $this->service(EntityManagerInterface::class)->clear();
    }

    /**
     * @return list<string>
     */
    private function tablesHoldingTheSubject(): array
    {
        $found = [];
        $columns = $this->connection()->fetchAllAssociative(
            'SELECT c.table_name, c.column_name FROM information_schema.columns c '
            . 'JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name '
            . "WHERE c.table_schema = 'public' AND t.table_type = 'BASE TABLE' "
            . "AND c.data_type IN ('uuid', 'character varying', 'text', 'json', 'jsonb') "
            . "AND c.table_name <> 'identity_erasure_resweep' ORDER BY c.table_name, c.column_name",
        );
        $this->assertGreaterThan(50, \count($columns), 'the sweep must cover the schema or it proves nothing');

        foreach ($columns as $column) {
            $table = $this->stringOf($column['table_name'] ?? null);
            $name = $this->stringOf($column['column_name'] ?? null);
            $count = $this->intOf($this->connection()->fetchOne(
                \sprintf('SELECT COUNT(*) FROM "%s" WHERE CAST("%s" AS TEXT) ILIKE :needle', $table, $name),
                ['needle' => '%' . $this->subjectId . '%'],
            ));

            if ($count > 0) {
                $found[] = $table . '.' . $name;
            }
        }

        return $found;
    }
}
