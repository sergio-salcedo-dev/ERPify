<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Read;

use Erpify\Documents\Document\Application\Scanning\WriteCallbackStream;
use Erpify\Documents\Document\Application\Upload\UploadEvidence;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Exception\DocumentGone;
use Erpify\Documents\Document\Domain\Exception\DocumentIntegrityFailure;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Exception\DocumentNotReadable;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\ObjectNotFound;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Crypto\Application\StreamEnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Domain\Exception\StreamTransferFailed;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads ONE document's original for a caller already authorised: refuses what cannot be read, proves the stored
 * bytes are the bytes that were filed, writes the audit row, and only then hands back the transfer that streams them.
 * It is the read boundary the shared crypto exceptions are translated at (#418): `DekDestroyed` and `DecryptionFailed`
 * carry no marker because the write path needs their 500, so the HTTP meaning is assigned here and nowhere else.
 *
 * **Two passes, because the answer must precede the first byte.** Pass 1 unseals into a digest that discards the
 * bytes and compares sha256 and size with the row; only a document that passes it is audited and served. Pass 2
 * unseals again into the caller's sink and re-checks as it goes ({@see DocumentTransfer::sendTo()}), so an object
 * swapped between the passes can abort the transfer and never be served as the original. Nothing is buffered and no
 * plaintext touches a file.
 *
 * **Translation.** Before either crypto outcome is answered the row is re-read: an `Erased` row answers
 * {@see DocumentGone} and raises no alarm (an erasure can commit between the snapshot and the open). Any other state
 * raises the alarm — the `observability` channel, the document id and a reason slug, nothing else — and a destroyed
 * key answers gone while a missing object, a failed authentication or a digest or size mismatch answers
 * {@see DocumentIntegrityFailure}, which carries no marker and so reaches the error tracker. A storage fault and a
 * failed transfer propagate untranslated: they are not a verdict on the document.
 *
 * **The audit row** (`DOCUMENT_READ`, level `security`, resource `Document`, no metadata) is written after pass 1
 * and before pass 2, so a failed write is a 5xx with no byte served. It records that access was granted, not that the
 * transfer completed; a refused read writes none. The writer owns no transaction and joins whatever is open, so call
 * {@see open()} with none: inside one, a rollback would take the row with it.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the orchestrator of one read
 */
final readonly class ReadDocument
{
    public const string AUDIT_ACTION = 'DOCUMENT_READ';

    private const string RESOURCE_TYPE = 'Document';

    public function __construct(
        private DocumentRepository $documents,
        private DocumentStorage $storage,
        private StreamEnvelopeEncryptor $encryptor,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws DocumentNotFound         when no row exists
     * @throws DocumentGone             when the row is `Erased`, or the key is destroyed under a live-looking row
     * @throws DocumentNotReadable      in every other state but `Ready`
     * @throws DocumentIntegrityFailure when the original is missing, fails authentication or no longer matches the row
     */
    public function open(string $documentId): DocumentTransfer
    {
        $document = $this->documents->findById($documentId) ?? throw DocumentNotFound::create();

        if (DocumentState::Erased === $document->state()) {
            throw DocumentGone::create();
        }

        $document->assertReadable();

        // Scalars from here on: the row is re-read, and a managed instance would move under the caller's feet. The
        // id is the row's own, never the caller's spelling of it: the lookup is case-insensitive, and an upper-cased
        // id would otherwise be audited and logged as a second document.
        $documentId = $document->documentId();
        $scopeId = $document->scopeId();
        $digest = $document->digest();
        $size = $document->sizeBytes();

        $this->verify($documentId, $scopeId, $digest, $size);

        $this->auditLogger->log(
            self::AUDIT_ACTION,
            AuditLevel::SECURITY,
            AuditResource::of(self::RESOURCE_TYPE, $documentId),
        );

        return new DocumentTransfer(
            $size,
            fn (callable $emit): bool => $this->transfer($documentId, $scopeId, $digest, $size, $emit),
        );
    }

    private function verify(string $documentId, string $scopeId, string $digest, int $size): void
    {
        $running = new RunningDigest();
        $overran = false;

        try {
            // Stops at the first chunk past the row's size, as the transfer does: an object swapped for a much larger
            // authentic one is not decrypted and hashed to its end before the size is compared.
            $this->unsealInto(
                $scopeId,
                static function (string $bytes) use ($running, $size, &$overran): bool {
                    $running->update($bytes);
                    $overran = $running->size() > $size;

                    return !$overran;
                },
            );
        } catch (StreamTransferFailed $cause) {
            throw $overran ? $this->refusal($documentId, 'size-mismatch') : $cause;
        } catch (DekDestroyed $cause) {
            throw $this->refusal($documentId, 'key-destroyed', $cause);
        } catch (DecryptionFailed $cause) {
            throw $this->refusal($documentId, 'authentication-failed', $cause);
        } catch (ObjectNotFound $cause) {
            throw $this->refusal($documentId, 'object-missing', $cause);
        }

        if ($running->size() !== $size) {
            throw $this->refusal($documentId, 'size-mismatch');
        }

        if (!$running->digestMatches($digest)) {
            throw $this->refusal($documentId, 'digest-mismatch');
        }
    }

    /**
     * @param callable(string): void $emit
     */
    private function transfer(string $documentId, string $scopeId, string $digest, int $size, callable $emit): bool
    {
        $running = new RunningDigest();
        $held = '';
        $overran = false;

        try {
            $this->unsealInto(
                $scopeId,
                static function (string $bytes) use ($running, $size, &$held, &$overran, $emit): bool {
                    $running->update($bytes);

                    if ($running->size() > $size) {
                        $overran = true;

                        return false;
                    }

                    $pending = $held . $bytes;
                    $held = \substr($pending, -1);
                    $ready = \substr($pending, 0, -1);

                    if ('' !== $ready) {
                        $emit($ready);
                    }

                    return true;
                },
            );
        } catch (Throwable $throwable) {
            // After the headers are out nothing can answer a status, so whatever ends the pass is an alarmed abort and
            // none escapes the response callback: a keystore or database fault included.
            return $this->abort($documentId, $overran ? 'size-mismatch' : $this->reasonOf($throwable));
        }

        $mismatch = $this->mismatchOf($running, $digest, $size);

        if (null !== $mismatch) {
            return $this->abort($documentId, $mismatch);
        }

        $emit($held);

        return true;
    }

    /**
     * Why the second pass no longer matches the row, as the reason slug of its alarm; null when it matches.
     */
    private function mismatchOf(RunningDigest $running, string $digest, int $size): ?string
    {
        if ($running->size() !== $size) {
            return 'size-mismatch';
        }

        return $running->digestMatches($digest) ? null : 'changed-between-passes';
    }

    /**
     * @param callable(string): bool $onBytes
     */
    private function unsealInto(string $scopeId, callable $onBytes): void
    {
        $sealed = $this->storage->open($scopeId);
        $plaintext = WriteCallbackStream::open($onBytes);

        try {
            $this->encryptor->unseal(
                EncryptionScopeId::forDocument($scopeId),
                $sealed,
                $plaintext,
                UploadEvidence::ORIGINAL_ASSOCIATED_DATA,
            );
        } finally {
            \fclose($plaintext);
            \fclose($sealed);
        }
    }

    private function refusal(string $documentId, string $reason, ?Throwable $cause = null): Throwable
    {
        if ($this->isErased($documentId)) {
            return DocumentGone::create();
        }

        $this->logger->error('A document could not be read and the read was refused.', [
            'documentId' => $documentId,
            'reason' => $reason,
        ]);

        return 'key-destroyed' === $reason ? DocumentGone::create() : DocumentIntegrityFailure::create($cause);
    }

    private function abort(string $documentId, string $reason): bool
    {
        if (!$this->isErased($documentId)) {
            $this->logger->error('A document transfer was aborted after it began.', [
                'documentId' => $documentId,
                'reason' => $reason,
            ]);
        }

        return false;
    }

    /**
     * Re-reads the row: `findById` refreshes it, so this is the state now and not the snapshot the read began with.
     */
    private function isErased(string $documentId): bool
    {
        try {
            $document = $this->documents->findById($documentId);
        } catch (Throwable) {
            // A failed re-read must not replace the outcome being translated or silence its alarm: the row is taken
            // as not erased, which errs toward the alarm. Nothing of the failure is logged — it may quote the id.
            return false;
        }

        return $document instanceof Document && DocumentState::Erased === $document->state();
    }

    private function reasonOf(Throwable $failure): string
    {
        return match (true) {
            $failure instanceof DekDestroyed => 'key-destroyed',
            $failure instanceof DecryptionFailed => 'authentication-failed',
            $failure instanceof ObjectNotFound => 'object-missing',
            default => 'transfer-failed',
        };
    }
}
