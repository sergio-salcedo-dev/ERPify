<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use Erpify\Documents\Document\Domain\OriginalFilename;

/**
 * An intent this request has just moved to `Streaming`, with what the stream and the commit need from it. The
 * filename lives here and in request memory only; the intent row never holds it.
 */
final readonly class ClaimedUpload
{
    public function __construct(
        public string $intentId,
        public string $scopeId,
        public int $declaredSize,
        public ?string $declaredSha256,
        public OriginalFilename $filename,
    ) {
    }
}
