<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain;

use InvalidArgumentException;

/**
 * What identifies the bytes of an original: its SHA-256 and its length. The two are computed over the same stream by
 * the flow that sealed it, travel together to every writer of a document, and are valid only together — a digest
 * with no bytes behind it, or bytes with no digest, is not an original. Immutable once filed.
 */
final readonly class OriginalContent
{
    public const int DIGEST_HEX_LENGTH = 64;

    public function __construct(
        public string $digest,
        public int $sizeBytes,
    ) {
        if (!self::isSha256Hex($digest)) {
            throw new InvalidArgumentException(
                'The digest must be a 64-character lower-case hexadecimal SHA-256 string.',
            );
        }

        if ($sizeBytes <= 0) {
            throw new InvalidArgumentException('The size must be a positive number of bytes.');
        }
    }

    /**
     * `D` is load-bearing: without it `$` also matches before a trailing newline.
     */
    public static function isSha256Hex(string $value): bool
    {
        return 1 === \preg_match('/^[0-9a-f]{' . self::DIGEST_HEX_LENGTH . '}$/D', $value);
    }
}
