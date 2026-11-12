<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Documents\Document\Application\Upload\ReapUploadIntents;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;

/**
 * Evidence ingest steps: closing the switch for one scenario, filing a document in a given state, and observing
 * what an aborted upload leaves on the document volume and in the keystore once the reaper has run.
 *
 * Closing the switch: the test environment reads `documents.ingest_enabled` from `DOCUMENTS_INGEST_ENABLED`, and
 * the kernel reboots between requests, so the next request's container reads the closed switch — the same
 * parameter the production gate reads as a tracked literal. Reopened after every scenario, so a scenario that
 * closes it cannot leak into the next.
 */
final class DocumentsIngestContext extends AbstractContext implements Context
{
    private const string VARIABLE = 'DOCUMENTS_INGEST_ENABLED';

    /** @var array<string, string> intent id => scope id, as they were before the reaper ran */
    private array $beforeReaping = [];

    public function __construct(
        private readonly ReapUploadIntents $reaper,
        private readonly DocumentStorage $storage,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Given('evidence ingest is switched off')]
    public function evidenceIngestIsSwitchedOff(): void
    {
        $this->set('false');
    }

    /**
     * Files a document row straight in the given state, so a scenario about an operator decision starts from the
     * state the scan would have left rather than running a scanner. The uploader is a fresh id that names no
     * identity; nothing here reads the sealed bytes.
     */
    #[Given('/^a document in state "([^"]*)" with id "([^"]*)"$/')]
    public function aDocumentInStateWithId(string $state, string $id): void
    {
        $scope = Uuid::generate();

        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO document (id, scope_id, series_id, uploader_id, state, digest, size_bytes, next_scan_at, '
            . 'created_at, updated_at) '
            . 'VALUES (:id, :scope, :id, :uploader, :state, :digest, 5, NOW(), NOW(), NOW())',
            [
                'id' => $id,
                'scope' => $scope,
                'uploader' => Uuid::generate(),
                'state' => $state,
                'digest' => \hash('sha256', $id),
            ],
        );
    }

    #[AfterScenario]
    public function reopenEvidenceIngest(): void
    {
        $this->set('true');
        $this->beforeReaping = [];
    }

    #[Then('no expired upload has a staged object left')]
    public function noExpiredUploadHasAStagedObjectLeft(): void
    {
        /** @var list<string> $scopes */
        $scopes = $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT scope_id FROM upload_intent WHERE state = 'expired'",
        );

        self::assertNotEmpty($scopes, 'There is no expired upload to look at.');

        foreach ($scopes as $scope) {
            self::assertFalse($this->storage->has(StorageArea::Staging, $scope), 'A staged object outlived its abort.');
        }
    }

    #[When('the upload reaper runs')]
    public function theUploadReaperRuns(): void
    {
        /** @var list<array{id: string, scope_id: string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative('SELECT id, scope_id FROM upload_intent');
        $this->beforeReaping = \array_column($rows, 'scope_id', 'id');

        $this->reaper->reap(500);
    }

    #[Then('the scope of every reaped upload is tombstoned as an aborted upload')]
    public function theScopeOfEveryReapedUploadIsTombstonedAsAnAbortedUpload(): void
    {
        $connection = $this->entityManager->getConnection();
        $reaped = 0;

        foreach ($this->beforeReaping as $intent => $scope) {
            if (false !== $connection->fetchOne('SELECT 1 FROM upload_intent WHERE id = :id', ['id' => $intent])) {
                continue;
            }

            ++$reaped;
            self::assertSame(
                'aborted-upload',
                $connection->fetchOne('SELECT destroy_reason FROM dek_keystore WHERE encryption_scope_id = :scope', [
                    'scope' => EncryptionScopeId::forDocument($scope)->toString(),
                ]),
            );
        }

        self::assertGreaterThan(0, $reaped, 'The reaper ended no upload.');
    }

    /**
     * @SuppressWarnings("PHPMD.Superglobals") Symfony's env processor reads `$_ENV`/`$_SERVER` before `getenv()`,
     * so the switch has to land in all three for the next kernel to see it.
     */
    private function set(string $value): void
    {
        $_ENV[self::VARIABLE] = $value;
        $_SERVER[self::VARIABLE] = $value;
        \putenv(self::VARIABLE . '=' . $value);
    }
}
