<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use Erpify\Documents\Document\Domain\Entity\Document;

/**
 * What an evidence PUT answers: the document it filed, or — for a PUT on an intent already committed — the
 * document an earlier PUT filed, so a lost response is recoverable without a key.
 */
final readonly class UploadOutcome
{
    private function __construct(
        public Document $document,
        public bool $created,
    ) {
    }

    public static function filed(Document $document): self
    {
        return new self($document, true);
    }

    public static function alreadyFiled(Document $document): self
    {
        return new self($document, false);
    }
}
