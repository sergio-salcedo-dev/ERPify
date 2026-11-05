<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Infrastructure;

use Erpify\Shared\Crypto\Application\StreamEnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\CannotSealEmptyStream;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\StreamTransferFailed;
use Override;
use SodiumException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Stream sealing over libsodium's `crypto_secretstream_xchacha20poly1305`, keyed by the scope's
 * data-encryption key from the {@see ScopeKeyring}. A sealed object is a one-byte format version, the
 * stream header (a fresh random value per object, so one key seals any number of objects), then fixed-size
 * chunks of {@see CHUNK_BYTES} of plaintext each plus the authentication tag, the last one shorter or equal.
 *
 * Every chunk is authenticated with the scope and the caller's context as additional-authenticated-data, and
 * the construction chains each chunk to the one before it, so a dropped, reordered or substituted chunk fails.
 * The end is authenticated too: the last chunk carries the library's FINAL tag, which is part of what the tag
 * covers, and a reader that reaches the end of the object without having seen it refuses the object. Which
 * chunk is the last is decided by reading ahead, never by guessing from the size, so a source that fails
 * while the next chunk is being read has not yet had its last chunk sealed as the end; and a source whose
 * length is an exact multiple of the chunk size ends in a full FINAL chunk, not in an empty one.
 *
 * Memory holds the current chunk, the one read ahead and one chunk of ciphertext, whatever the stream's size.
 */
#[AsAlias(StreamEnvelopeEncryptor::class)]
final readonly class SodiumStreamEnvelopeEncryptor implements StreamEnvelopeEncryptor
{
    public const int CHUNK_BYTES = 65536;

    private const string FORMAT_VERSION = "\x01";

    private const int TAG_BYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

    private const int HEADER_BYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;

    public function __construct(
        private ScopeKeyring $keyring,
    ) {
    }

    #[Override]
    public function seal(EncryptionScopeId $scope, $plaintext, $sealed, string $aad): void
    {
        $current = $this->readChunk($scope, $plaintext, self::CHUNK_BYTES);

        // Before any key exists: a source that produced nothing mints nothing.
        if ('' === $current) {
            throw CannotSealEmptyStream::forScope($scope);
        }

        $dek = $this->keyring->keyForSealing($scope);
        /** @var array{0: string, 1: string} $initialised */
        $initialised = \sodium_crypto_secretstream_xchacha20poly1305_init_push($dek);
        [$state, $header] = $initialised;
        \sodium_memzero($dek);

        try {
            $this->write($scope, $sealed, self::FORMAT_VERSION . $header);
            $associatedData = $this->keyring->aad($scope, $aad);

            do {
                // A chunk shorter than the maximum already met the end of the source; only a full one needs
                // the read-ahead that tells whether more follows.
                $next = self::CHUNK_BYTES === \strlen($current)
                    ? $this->readChunk($scope, $plaintext, self::CHUNK_BYTES)
                    : '';
                $final = '' === $next;

                $this->write($scope, $sealed, \sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $current,
                    $associatedData,
                    $final
                        ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                        : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                ));
                $current = $next;
            } while (!$final);
        } finally {
            \sodium_memzero($state);
        }
    }

    #[Override]
    public function unseal(EncryptionScopeId $scope, $sealed, $plaintext, string $aad): void
    {
        $dek = $this->keyring->keyForOpening($scope);

        try {
            $state = $this->openState($scope, $sealed, $dek);
        } finally {
            \sodium_memzero($dek);
        }

        try {
            $associatedData = $this->keyring->aad($scope, $aad);
            $final = false;

            while (!$final) {
                $record = $this->readChunk($scope, $sealed, self::CHUNK_BYTES + self::TAG_BYTES);

                // Reaching the end of the object before the FINAL chunk is a truncation, not a short file.
                if ('' === $record) {
                    throw DecryptionFailed::forScope($scope);
                }

                $opened = $this->open($scope, $state, $record, $associatedData);
                $this->write($scope, $plaintext, $opened[0]);
                $final = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $opened[1];
            }

            // Whatever follows the FINAL chunk was not sealed by this stream.
            if ('' !== $this->readChunk($scope, $sealed, 1)) {
                throw DecryptionFailed::forScope($scope);
            }
        } finally {
            \sodium_memzero($state);
        }
    }

    /**
     * @param resource $sealed
     */
    private function openState(EncryptionScopeId $scope, $sealed, string $dek): string
    {
        $prefix = $this->readChunk($scope, $sealed, 1 + self::HEADER_BYTES);

        if (1 + self::HEADER_BYTES !== \strlen($prefix) || self::FORMAT_VERSION !== $prefix[0]) {
            throw DecryptionFailed::forScope($scope);
        }

        return \sodium_crypto_secretstream_xchacha20poly1305_init_pull(\substr($prefix, 1), $dek);
    }

    /**
     * @return array{0: string, 1: int} the plaintext and the tag the chunk was sealed with
     */
    private function open(EncryptionScopeId $scope, string &$state, string $record, string $associatedData): array
    {
        try {
            /** @var array{0: string, 1: int}|false $opened */
            $opened = \sodium_crypto_secretstream_xchacha20poly1305_pull($state, $record, $associatedData);
        } catch (SodiumException) {
            throw DecryptionFailed::forScope($scope);
        }

        if (false === $opened) {
            throw DecryptionFailed::forScope($scope);
        }

        return $opened;
    }

    /**
     * Reads up to `$length` bytes, looping over short reads: fewer come back only at the end of the stream.
     *
     * @param resource $stream
     */
    private function readChunk(EncryptionScopeId $scope, $stream, int $length): string
    {
        $buffer = '';

        while (($missing = $length - \strlen($buffer)) > 0 && !\feof($stream)) {
            $read = \fread($stream, $missing);

            if (false === $read) {
                throw StreamTransferFailed::forScope($scope);
            }

            $buffer .= $read;
        }

        return $buffer;
    }

    /**
     * @param resource $stream
     */
    private function write(EncryptionScopeId $scope, $stream, string $bytes): void
    {
        $length = \strlen($bytes);

        for ($written = 0; $written < $length;) {
            $count = \fwrite($stream, \substr($bytes, $written));

            if (false === $count || 0 === $count) {
                throw StreamTransferFailed::forScope($scope);
            }

            $written += $count;
        }
    }
}
