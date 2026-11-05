<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Application;

use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\CannotSealEmptyStream;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Domain\Exception\StreamTransferFailed;

/**
 * The stream sibling of {@see EnvelopeEncryptor}: seals and opens a byte stream under the same per-scope
 * key with peak memory independent of the stream's size. The scope is bound as additional-authenticated-data
 * on every chunk, as is the caller's `$aad`, which is not stored and must be passed identically to
 * {@see unseal()}; neither is a storage path, so a sealed object can be renamed or moved without re-sealing.
 *
 * The sealed form authenticates its own end: a stream cut short, with chunks dropped, reordered or appended
 * to, or with nothing in it, fails to open. Because chunks are opened as they arrive, a failing
 * {@see unseal()} may already have written a prefix of the plaintext to its sink; the caller treats the sink
 * as discarded on any exception.
 */
interface StreamEnvelopeEncryptor
{
    /**
     * Reads `$plaintext` to its end and writes the sealed form to `$sealed`, minting the scope's key on first
     * use. The key is minted once the first chunk has been read, so a source that is empty or fails before
     * that chunk completes leaves no keystore row. A failure after it leaves a live key and a partial object
     * in `$sealed`: the caller discards the object and tombstones the scope with
     * {@see DestroyReason::AbortedUpload}, which is only right when no Document row refers to the scope.
     *
     * Run it outside any transaction that can roll back. The minted key row enrols in the caller's open
     * transaction while the sealed bytes go to an external sink, so a rollback would delete the key of an
     * object that already exists, and an uncommitted insert holds the scope's unique-index lock for the whole
     * outer transaction, blocking every other writer and any destroy of the scope.
     *
     * The original's filename is sealed with {@see EnvelopeEncryptor::encrypt()} under the same scope. A
     * filename ciphertext and a stream object cannot stand in for one another by construction (different
     * primitives and formats), even with equal `$aad`; passing a different `$aad` for the filename is defence in
     * depth.
     *
     * Both streams must be blocking, and the sink must support `fflush()`: a userland wrapper without
     * `stream_flush` makes it return false, and `seal()` then throws {@see StreamTransferFailed} after a
     * complete object was written. Three consecutive empty reads that report no end of stream are refused, so a
     * non-blocking source with nothing ready is refused rather than waited for; a slow drip of one byte at a
     * time is never refused and is bounded by the caller's own deadline.
     *
     * @param resource $plaintext a readable stream
     * @param resource $sealed    a writable stream
     *
     * @throws CannotSealEmptyStream when the stream yields no bytes
     * @throws DekDestroyed          when the scope has been destroyed
     * @throws StreamTransferFailed  when a read or a write fails
     */
    public function seal(EncryptionScopeId $scope, $plaintext, $sealed, string $aad): void;

    /**
     * Reads a sealed stream to its end, writing the plaintext to `$plaintext` and flushing it. The same
     * stream requirements as {@see seal()} apply.
     *
     * @param resource $sealed    a readable stream holding the output of {@see seal()}
     * @param resource $plaintext a writable stream
     *
     * @throws DekDestroyed         when the scope's key is absent or has been destroyed
     * @throws DecryptionFailed     when the stream is empty, truncated, extended, altered, or sealed under
     *                              another scope or `$aad`
     * @throws StreamTransferFailed when a read or a write fails
     */
    public function unseal(EncryptionScopeId $scope, $sealed, $plaintext, string $aad): void;
}
