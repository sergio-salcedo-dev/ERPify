<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Http;

use DateTimeInterface;
use Erpify\Documents\Document\Application\Resource\DocumentReceiptResource;
use Erpify\Documents\Document\Application\Resource\UploadResource;
use Erpify\Documents\Document\Application\Upload\DeclareUploadOutcome;
use Erpify\Documents\Document\Domain\Entity\Document;

/**
 * Maps the ingest outcomes to their wire resources. Nothing here reads the sealed filename or the key hash.
 */
final readonly class UploadResourceMapper
{
    public function toUploadResource(DeclareUploadOutcome $outcome): UploadResource
    {
        if ($outcome->document instanceof Document) {
            return new UploadResource(
                $outcome->intentId(),
                $outcome->document->state()->value,
                $outcome->document->documentId(),
                null,
            );
        }

        $intent = $outcome->intent;
        \assert($intent instanceof \Erpify\Documents\Document\Domain\Entity\UploadIntent);

        return new UploadResource(
            $intent->id(),
            $intent->state()->value,
            null,
            $intent->ttlExpiresAt()->format(DateTimeInterface::ATOM),
        );
    }

    public function toReceipt(Document $document): DocumentReceiptResource
    {
        return new DocumentReceiptResource(
            $document->documentId(),
            $document->state()->value,
            $document->digest(),
            $document->sizeBytes(),
        );
    }
}
