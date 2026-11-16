<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Infrastructure\Http;

use SensitiveParameter;

/**
 * What an image write's HTTP edge hands to the use case: the bytes the request carried and, when the caller
 * declared one the processor can be asked to cross-check, its media type. Nothing about the transport survives
 * into it — no stream, no header bag, no filename.
 */
final readonly class ImageRequestBody
{
    public function __construct(
        #[SensitiveParameter]
        public string $bytes,
        public ?string $declaredMediaType,
    ) {
    }
}
