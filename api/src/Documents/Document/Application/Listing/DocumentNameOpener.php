<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Listing;

use Erpify\Documents\Document\Application\Upload\CommitUpload;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Application\SealedValue;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Domain\Exception\InvalidEncryptionScopeId;

/**
 * Opens the sealed original filenames of a set of documents, once per document, with ONE keystore query for the set
 * (`EnvelopeEncryptor::decryptMany()`). It is the read boundary the shared crypto exceptions are translated at for a
 * name, and the single place that knows how a name opens: the listing and the scheduled report both go
 * through it.
 *
 * **Degrades per document, never fails the set.** A source with no ciphertext costs no lookup and has no name. A
 * destroyed or never-minted scope (`dek-destroyed`, the expected outcome after an erasure) is a
 * {@see NameCause::KeyMissing}; a failed authentication or a corrupt key row (`decryption-failed`, an integrity
 * signal) is a {@see NameCause::DecryptionFailed}; a scope that cannot be named or a plaintext that is not text is a
 * {@see NameCause::Unreadable}. None raises anything here: the request path logs nothing about a name, and the alarm
 * is the scheduled report's. A keystore or database outage still throws, and the page fails with it.
 *
 * The name is never put in a message, a context or an exception.
 */
final readonly class DocumentNameOpener
{
    public function __construct(
        private EnvelopeEncryptor $encryptor,
    ) {
    }

    /**
     * @param list<NameSource> $sources
     *
     * @return array<string, OpenedName> keyed by document id
     */
    public function open(array $sources): array
    {
        $outcomes = [];
        $sealed = [];

        foreach ($sources as $source) {
            if (null === $source->filenameCiphertext) {
                $outcomes[$source->documentId] = OpenedName::absent();

                continue;
            }

            try {
                $sealed[$source->documentId] = new SealedValue(
                    EncryptionScopeId::forDocument($source->scopeId),
                    $source->filenameCiphertext,
                    CommitUpload::filenameAssociatedData($source->scopeId),
                );
            } catch (InvalidEncryptionScopeId) {
                $outcomes[$source->documentId] = OpenedName::failed(NameCause::Unreadable);
            }
        }

        // Nothing sealed means nothing to ask the keystore for: not even the empty batch costs a query.
        $opened = [] === $sealed ? [] : $this->encryptor->decryptMany($sealed);

        foreach ($opened as $documentId => $outcome) {
            $outcomes[(string) $documentId] = $this->outcomeOf($outcome);
        }

        return $outcomes;
    }

    private function outcomeOf(DecryptionFailed|DekDestroyed|string $opened): OpenedName
    {
        return match (true) {
            $opened instanceof DekDestroyed => OpenedName::failed(NameCause::KeyMissing),
            $opened instanceof DecryptionFailed => OpenedName::failed(NameCause::DecryptionFailed),
            // The wire is JSON: a name that is not valid UTF-8 would fail the whole page, so it degrades like the rest.
            !\mb_check_encoding($opened, 'UTF-8') => OpenedName::failed(NameCause::Unreadable),
            default => OpenedName::named($opened),
        };
    }
}
