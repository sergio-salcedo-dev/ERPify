<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Projection;

use DateTimeImmutable;
use Erpify\Documents\Document\Domain\DocumentState;

/**
 * One version of a series as the series projection reads it, never the aggregate.
 *
 * `uploaderId` is NULL for an `Erased` version, chosen by the query rather than by a mapper: a tombstone keeps its id,
 * digest, size, retention, links, state and timestamps, and nothing that names a person reaches a reader through it.
 * The column itself stays on the row under the person purge's ownership. For a version whose uploader was erased it is
 * the nil UUID, a marker and never a user. No sealed name and no key material travel here.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final readonly class DocumentVersionRow
{
    public function __construct(
        public string $id,
        public ?string $predecessorId,
        public DocumentState $state,
        public string $digest,
        public int $sizeBytes,
        public ?string $retentionReason,
        public ?string $legalBasis,
        public ?DateTimeImmutable $reviewAt,
        public ?string $uploaderId,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
