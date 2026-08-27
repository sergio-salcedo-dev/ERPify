<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain;

/**
 * The output of {@see ImageProcessor::process()}: the canonical bytes and the attributes derived
 * from them. Carries no {@see ImageId} — that is minted by {@see \Erpify\Shared\Images\Application\UploadImage},
 * never by the processor (FR7: a second producer that does not know `UploadImage` must be able to
 * invoke the processor on its own).
 *
 * `digest` and `byteSize` are derived here from `$bytes`, never accepted as separate constructor
 * arguments — the only way to guarantee neither can diverge from the actual canonical bytes.
 */
final readonly class CanonicalImage
{
    public string $digest;

    public int $byteSize;

    public function __construct(
        public string $bytes,
        public string $mediaType,
        public int $width,
        public int $height,
    ) {
        $this->digest = \hash('sha256', $this->bytes);
        $this->byteSize = \strlen($this->bytes);
    }
}
