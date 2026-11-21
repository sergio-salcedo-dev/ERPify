<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Http;

use DateTimeInterface;
use Erpify\Documents\Document\Application\Erasure\SeriesErasure;
use Erpify\Documents\Document\Application\Listing\DocumentSeriesPage;
use Erpify\Documents\Document\Application\Resource\DocumentSeriesResource;
use Erpify\Documents\Document\Application\Resource\DocumentVersionResource;
use Erpify\Documents\Document\Application\Resource\SeriesErasureResource;
use Erpify\Documents\Document\Domain\Projection\DocumentVersionRow;

/**
 * Maps a series page and a series erasure to their wire resources, owning the ATOM spelling of their timestamps.
 */
final readonly class DocumentSeriesMapper
{
    public function toSeriesResource(DocumentSeriesPage $page): DocumentSeriesResource
    {
        return new DocumentSeriesResource(
            $page->seriesId,
            \array_map($this->toVersionResource(...), $page->versions),
            $page->nextAfterId,
        );
    }

    public function toErasureResource(SeriesErasure $erasure): SeriesErasureResource
    {
        return new SeriesErasureResource($erasure->seriesId, $erasure->erased, $erasure->remaining);
    }

    private function toVersionResource(DocumentVersionRow $row): DocumentVersionResource
    {
        return new DocumentVersionResource(
            $row->id,
            $row->predecessorId,
            $row->state->value,
            $row->digest,
            $row->sizeBytes,
            $row->retentionReason,
            $row->legalBasis,
            $row->reviewAt?->format(DateTimeInterface::ATOM),
            $row->uploaderId,
            $row->createdAt->format(DateTimeInterface::ATOM),
        );
    }
}
