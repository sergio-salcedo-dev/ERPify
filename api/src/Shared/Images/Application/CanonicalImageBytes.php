<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Application;

/**
 * What a verified read hands back: the canonical bytes plus the two attributes the delivery adapter needs
 * to describe them — the media type it must declare, and the digest it turns into a validator.
 *
 * It carries the digest rather than letting the adapter re-hash the bytes, because the value here is the
 * one {@see CanonicalImageFinder} has already compared against the row: re-deriving it downstream would
 * give the response a validator that agrees with the bytes even when the bytes disagree with the row.
 *
 * Deliberately not {@see \Erpify\Shared\Images\Domain\CanonicalImage}, which is the PROCESSOR's output and
 * derives its own digest from whatever it is handed. Reusing it would mean fabricating a width and a height
 * from the row to re-derive a value the finder already holds, and would move the comparison onto a class
 * whose constructor validates nothing.
 */
final readonly class CanonicalImageBytes
{
    public function __construct(
        public string $bytes,
        public string $mediaType,
        public string $digest,
    ) {
    }
}
