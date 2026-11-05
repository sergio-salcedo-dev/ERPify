<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Application;

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
     * use. The key is minted only once the first byte has arrived, so an empty or failing source leaves no
     * keystore row.
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
     * Reads a sealed stream to its end, writing the plaintext to `$plaintext`.
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
