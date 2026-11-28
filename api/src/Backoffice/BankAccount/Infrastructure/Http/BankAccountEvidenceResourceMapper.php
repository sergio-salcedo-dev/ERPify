<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Http;

use DateTimeInterface;
use Erpify\Backoffice\BankAccount\Application\Evidence\ListedEvidence;
use Erpify\Backoffice\BankAccount\Application\Resource\BankAccountEvidenceResource;

/**
 * Maps a piece of listed evidence to its wire shape: the kind as its identity value, the instant as ATOM.
 */
final readonly class BankAccountEvidenceResourceMapper
{
    public function toResource(ListedEvidence $evidence): BankAccountEvidenceResource
    {
        return new BankAccountEvidenceResource(
            $evidence->id,
            $evidence->documentId,
            $evidence->kind->value,
            $evidence->name,
            $evidence->state,
            $evidence->digest,
            $evidence->sizeBytes,
            $evidence->attachedAt->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * @param list<ListedEvidence> $evidence
     *
     * @return list<BankAccountEvidenceResource>
     */
    public function toResources(array $evidence): array
    {
        return \array_map($this->toResource(...), $evidence);
    }
}
