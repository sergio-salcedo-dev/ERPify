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

    /** The size cap of an original (100 MiB), an exact multiple of the chunk size. */
    public const int MAX_ORIGINAL_BYTES = 104_857_600;

    private function __construct()
    {
    }
}
