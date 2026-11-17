<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Documents\Document\Application\Upload\CommitUpload;
use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * Steps for the document listing and the custody trail: a document whose original filename is really sealed under its
 * own scope (so the listing opens it), the destruction of that scope's key, and a custody trail of a known length.
 * Nothing here files or reads the bytes of an original: neither route does.
 */
final class DocumentsCustodyContext extends AbstractContext implements Context
{
    /** @var array<string, string> document id => scope id */
    private array $scopes = [];

    public function __construct(
        private readonly EnvelopeEncryptor $encryptor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Files a document row in the given state whose name is sealed under a fresh scope; `$ageSeconds` is how long
     * ago it was created, so a scenario controls the order of the listing.
     */
    #[Given('/^a document with id "([^"]*)" named "([^"]*)" in state "([^"]*)" created (\d+) seconds ago$/')]
    public function aDocumentWithIdNamedInStateCreatedSecondsAgo(
        string $id,
        string $name,
        string $state,
        int $ageSeconds,
    ): void {
        $scope = RandomUuid::v4()->toRfc4122();
        $this->scopes[$id] = $scope;

        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO document (id, scope_id, series_id, uploader_id, state, digest, size_bytes, '
            . 'filename_ciphertext, retention_reason, legal_basis, next_scan_at, created_at, updated_at) '
            . 'VALUES (:id, :scope, :id, :uploader, :state, :digest, 5, :ciphertext, :retention, :basis, NOW(), '
            . 'NOW() - make_interval(secs => :age), NOW())',
            [
                'id' => $id,
                'scope' => $scope,
                'uploader' => Uuid::generate(),
                'state' => $state,
                'digest' => \hash('sha256', $id),
                'ciphertext' => $this->encryptor->encrypt(
                    EncryptionScopeId::forDocument($scope),
                    $name,
                    CommitUpload::filenameAssociatedData($scope),
                ),
                'retention' => 'contract-execution',
                'basis' => 'legitimate-interests',
                'age' => $ageSeconds,
            ],
        );
    }

    #[Given('/^the key of document "([^"]*)" is destroyed$/')]
    public function theKeyOfDocumentIsDestroyed(string $id): void
    {
        $scope = $this->scopes[$id] ?? null;
        self::assertNotNull($scope, 'This scenario filed no sealed name for that document.');

        $this->encryptor->destroyScope(EncryptionScopeId::forDocument($scope), DestroyReason::Erasure);
    }

    /**
     * Plants `$count` custody rows numbered from 1: a `received` fact by a person, then the system's scan claims.
     */
    #[Given('/^the document "([^"]*)" has (\d+) custody rows$/')]
    public function theDocumentHasCustodyRows(string $id, int $count): void
    {
        for ($seq = 1; $seq <= $count; ++$seq) {
            $this->entityManager->getConnection()->executeStatement(
                'INSERT INTO document_custody (id, document_id, seq, event, actor_id, created_at) '
                . 'VALUES (:id, :document, :seq, :event, :actor, NOW() + make_interval(secs => :seq))',
                [
                    'id' => Uuid::generate(),
                    'document' => $id,
                    'seq' => $seq,
                    'event' => 1 === $seq ? CustodyEvent::Received->value : CustodyEvent::ScanClaimed->value,
                    'actor' => 1 === $seq ? Uuid::generate() : null,
                ],
            );
        }
    }

    /**
     * What a person's erasure did to the trail: every actor it named is now the one marker.
     */
    #[Given('/^the people named by the custody rows of document "([^"]*)" have been erased$/')]
    public function thePeopleNamedByTheCustodyRowsHaveBeenErased(string $id): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE document_custody SET actor_id = CAST(:marker AS UUID) '
            . 'WHERE document_id = CAST(:id AS UUID) AND actor_id IS NOT NULL',
            ['marker' => ErasedPersonMarker::UUID, 'id' => $id],
        );
    }
}
