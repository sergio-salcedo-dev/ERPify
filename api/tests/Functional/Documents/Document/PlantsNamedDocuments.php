<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\Upload\CommitUpload;
use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * Documents whose original filename is really sealed under their own scope, planted through DBAL so a test controls the
 * state, the creation instant and the key. The bytes of the original are not filed: the listing and the trail never
 * read them.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
 */
trait PlantsNamedDocuments
{
    use PlantsDocumentRows;
    use ResolvesContainerServices;

    /**
     * Files a document row whose name is sealed with the container's encryptor, `$ageSeconds` old, and returns its id
     * and scope. A `null` name files no ciphertext (the shape of an erased row).
     *
     * @return array{id: string, scope: string}
     */
    private function plantNamedDocument(
        ?string $name,
        DocumentState $state = DocumentState::Ready,
        int $ageSeconds = 0,
    ): array {
        $id = Uuid::generate();
        $scope = RandomUuid::v4()->toRfc4122();

        $ciphertext = null === $name ? null : $this->service(EnvelopeEncryptor::class)->encrypt(
            EncryptionScopeId::forDocument($scope),
            $name,
            CommitUpload::filenameAssociatedData($scope),
        );

        $this->connection()->executeStatement(
            'INSERT INTO document (id, scope_id, series_id, predecessor_id, uploader_id, state, digest, size_bytes, '
            . 'filename_ciphertext, retention_reason, legal_basis, next_scan_at, created_at, updated_at) '
            . 'VALUES (:id, :scope, :id, NULL, :uploader, :state, :digest, 10, :ciphertext, :retention, :basis, '
            . 'NOW(), NOW() - make_interval(secs => :age), NOW())',
            [
                'id' => $id,
                'scope' => $scope,
                'uploader' => Uuid::generate(),
                'state' => $state->value,
                'digest' => \hash('sha256', $id),
                'ciphertext' => $ciphertext,
                'retention' => 'contract-execution',
                'basis' => 'legitimate-interests',
                'age' => $ageSeconds,
            ],
        );

        return ['id' => $id, 'scope' => $scope];
    }

    private function destroyScopeOf(string $scope): void
    {
        $this->service(EnvelopeEncryptor::class)->destroyScope(
            EncryptionScopeId::forDocument($scope),
            DestroyReason::Erasure,
        );
    }

    /**
     * Flips the last byte of the sealed name, so its authentication fails while the key stays live.
     */
    private function tamperWithTheNameOf(string $documentId): void
    {
        $ciphertext = $this->connection()->fetchOne(
            'SELECT filename_ciphertext FROM document WHERE id = CAST(:id AS UUID)',
            ['id' => $documentId],
        );
        $this->assertIsString($ciphertext);
        $raw = \sodium_base642bin($ciphertext, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $raw[\strlen($raw) - 1] = \chr(\ord($raw[\strlen($raw) - 1]) ^ 0xFF);

        $this->connection()->executeStatement(
            'UPDATE document SET filename_ciphertext = :ciphertext WHERE id = CAST(:id AS UUID)',
            ['ciphertext' => \sodium_bin2base64($raw, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), 'id' => $documentId],
        );
    }

    /**
     * Plants `$count` custody rows for the document, `seq` 1 to `$count`, the first a `received` fact by `$actorId`
     * and the rest re-scans by the same person, so an ordering mistake cannot hide behind identical rows.
     */
    private function plantTrail(string $documentId, int $count, ?string $actorId = null): void
    {
        for ($seq = 1; $seq <= $count; ++$seq) {
            $rescan = $seq > 1;

            $this->connection()->executeStatement(
                'INSERT INTO document_custody (id, document_id, seq, event, actor_id, reason, created_at) '
                . 'VALUES (:id, :document, :seq, :event, :actor, :reason, NOW() + make_interval(secs => :seq))',
                [
                    'id' => Uuid::generate(),
                    'document' => $documentId,
                    'seq' => $seq,
                    'event' => $rescan ? CustodyEvent::RescanRequested->value : CustodyEvent::Received->value,
                    'actor' => $actorId ?? Uuid::generate(),
                    'reason' => $rescan ? 'failure_investigated' : null,
                ],
            );
        }
    }

    /**
     * The key-encryption key the booted kernel wraps with, so a key minted through the container opens in a stack a
     * test builds by hand.
     *
     * @SuppressWarnings("PHPMD.Superglobals") `$_SERVER`/`$_ENV` is where the dotenv-loaded variable lives, which is
     * where this project reads the environment from outside the container
     */
    private function auditKek(): string
    {
        $kek = $_SERVER['AUDIT_KEK'] ?? $_ENV['AUDIT_KEK'] ?? \getenv('AUDIT_KEK');
        $this->assertIsString($kek);

        return $kek;
    }
}
