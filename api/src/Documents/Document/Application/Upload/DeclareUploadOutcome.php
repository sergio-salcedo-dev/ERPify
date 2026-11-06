<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\UploadIntent;
use LogicException;

/**
 * What a declare answers: a fresh intent, the intent an earlier declare with the same key created and that is
 * still waiting for its body, or the document an earlier upload with the same key already filed — in any state,
 * an erased one included, since a replay never revives anything.
 */
final readonly class DeclareUploadOutcome
{
    private function __construct(
        public ?UploadIntent $intent,
        public ?Document $document,
        public bool $created,
    ) {
    }

    public static function created(UploadIntent $intent): self
    {
        return new self($intent, null, true);
    }

    public static function replayedIntent(UploadIntent $intent): self
    {
        return new self($intent, null, false);
    }

    public static function replayedDocument(Document $document, ?UploadIntent $intent = null): self
    {
        return new self($intent, $document, false);
    }

    public function intentId(): ?string
    {
        return $this->intent?->id();
    }

    public function documentId(): ?string
    {
        if (!$this->document instanceof Document && !$this->intent instanceof UploadIntent) {
            throw new LogicException('A declare outcome names an intent or a document.');
        }

        return $this->document?->documentId();
    }
}
