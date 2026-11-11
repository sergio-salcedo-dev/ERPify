<?php

declare(strict_types=1);

namespace Erpify\Tests\Double\Stream;

use Erpify\Shared\Crypto\Infrastructure\SodiumStreamEnvelopeEncryptor;

/**
 * The dimensions of a sealed stream as the tests measure it: where the chunks start and how big each record is.
 */
final class SealedLayout
{
    public const int CHUNK = SodiumStreamEnvelopeEncryptor::CHUNK_BYTES;

    /** One format-version byte, then the stream header. */
    public const int PREFIX_BYTES = 1 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;

    public const int TAG_BYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

    /** A full record: one chunk of plaintext and its authentication tag. */
    public const int RECORD_BYTES = self::CHUNK + self::TAG_BYTES;

    private function __construct()
    {
    }
}
