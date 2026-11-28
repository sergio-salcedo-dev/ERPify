<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Http;

use DateTimeInterface;
use Erpify\Backoffice\BankAccount\Application\Evidence\AttachedEvidence;
use Erpify\Backoffice\BankAccount\Application\Resource\BankAccountEvidenceResource;

final readonly class BankAccountEvidenceResourceMapper
{
    public function toResource(AttachedEvidence $evidence): BankAccountEvidenceResource
    {
        return new BankAccountEvidenceResource(
            $evidence->link->id(),
            $evidence->link->documentId(),
            $evidence->link->kind()->value,
            $evidence->link->attachedAt()->format(DateTimeInterface::ATOM),
            $evidence->document?->state,
            $evidence->document?->name,
            $evidence->document?->sizeBytes,
        );
    }

    /**
     * @param list<AttachedEvidence> $evidence
     *
     * @return list<BankAccountEvidenceResource>
     */
    public function toResources(array $evidence): array
    {
        return \array_map($this->toResource(...), $evidence);
    }
}
