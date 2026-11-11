<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload\Command;

use Erpify\Documents\Document\Domain\FilenameSubmission;

/**
 * Input of {@see \Erpify\Documents\Document\Application\Upload\UploadEvidence}: the raw-body PUT of an upload
 * intent, as the HTTP edge read it and before anything is validated.
 *
 * `filename` is already judged by the edge — a valid name or a refusal, never the raw string — and is read only
 * when the intent is still `Declared`. `contentLength` is NULL when the header was absent and -1 when it was not
 * a non-negative integer.
 */
final readonly class UploadEvidenceCommand
{
    /**
     * @param resource $body the request body, read once, to its end
     */
    public function __construct(
        public string $uploadId,
        public string $actorId,
        public ?int $contentLength,
        public FilenameSubmission $filename,
        public mixed $body,
    ) {
    }
}
