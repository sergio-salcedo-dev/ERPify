<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use Erpify\Documents\Document\Application\ReceiveDocument;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\UploadIntent;
use Erpify\Documents\Document\Domain\Exception\UploadAborted;
use Erpify\Documents\Document\Domain\IntakeMetadata;
use Erpify\Documents\Document\Domain\Repository\UploadIntentRepository;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\KeyState;
use Erpify\Shared\Persistence\Application\TransactionManager;

/**
 * The PUT's last transaction, after every byte is sealed and staged: it files the `Received` document and its
 * custody row 1 and moves the intent to `Committed`, or refuses and leaves nothing.
 *
 * **Lock order: the intent row, then the keystore row of the scope** — the order the reaper takes them in, so
 * exactly one of the two decides a given upload. Under both locks it asserts that the intent is still
 * `Streaming` with a live lease and a deadline not passed, and that the scope's key is `Live`: `Absent` is a
 * snapshot that cannot be locked and never passes, and a tombstone means the reaper already decided.
 *
 * **Everything the document records about who filed it comes from the locked row** — the actor and the key
 * hash — never from the writer's memory, so a person purge that ran during the stream is honoured.
 *
 * The original filename is sealed here, under the document's own scope and with its own associated data
 * (the scope and a filename purpose), because this is the first moment both the key and the decision to file
 * exist; it shares no domain with the stream's chunks.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the intent, keystore, sealer and filing it locks together
 */
final readonly class CommitUpload
{
    public const string FILENAME_PURPOSE = 'document-filename';

    public function __construct(
        private UploadIntentRepository $intents,
        private Keystore $keystore,
        private EnvelopeEncryptor $encryptor,
        private ReceiveDocument $receiveDocument,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @throws UploadAborted when the intent is gone, no longer live, or its scope is not live
     */
    public function commit(ClaimedUpload $claimed, string $digest): Document
    {
        return $this->transactionManager->transactional(
            fn (): Document => $this->commitWithin($claimed, $digest),
        );
    }

    public static function filenameAssociatedData(string $scopeId): string
    {
        return self::FILENAME_PURPOSE . ':' . $scopeId;
    }

    private function commitWithin(ClaimedUpload $claimed, string $digest): Document
    {
        $intent = $this->intents->lockForUpdate($claimed->intentId);

        if (!$intent instanceof UploadIntent) {
            throw UploadAborted::create();
        }

        $scope = EncryptionScopeId::forDocument($intent->scopeId());

        if (KeyState::Live !== $this->keystore->lockState($scope)) {
            throw UploadAborted::create();
        }

        $now = $this->intents->now();

        if (UploadIntentState::Streaming !== $intent->effectiveState($now)) {
            throw UploadAborted::create();
        }

        $intake = new IntakeMetadata(
            filenameCiphertext: $this->encryptor->encrypt(
                $scope,
                $claimed->filename->value(),
                self::filenameAssociatedData($intent->scopeId()),
            ),
            retentionReason: $intent->retentionReason()->value,
            legalBasis: $intent->legalBasis()->value,
            reviewAt: $intent->reviewAt(),
        );

        $document = $this->receiveDocument->receiveWithin(
            $intent->scopeId(),
            $intent->actorId(),
            $digest,
            $intent->declaredSize(),
            $intent->keyHash(),
            $intake,
            $now,
        );

        $intent->commit($document->documentId(), $now);
        $this->intents->save($intent);

        return $document;
    }
}
