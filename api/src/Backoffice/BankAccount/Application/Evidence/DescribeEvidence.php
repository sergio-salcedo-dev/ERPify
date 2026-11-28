<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Evidence;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Documents\Document\Application\Evidence\EvidenceDocuments;

/**
 * Composes links with what Documents says of their documents, through its published read seam: one call per set, so
 * the names of a whole account's evidence cost one keystore query. A link whose document the seam does not return is
 * left out rather than shown half-empty — the foreign key makes that a row deleted outside every path this code owns.
 */
final readonly class DescribeEvidence
{
    public function __construct(
        private EvidenceDocuments $documents,
    ) {
    }

    /**
     * @param list<BankAccountEvidence> $links
     *
     * @return list<ListedEvidence>
     */
    public function of(array $links): array
    {
        // Asked even for no link, so the erasure ledger's override answers the same 410 whether or not the account
        // holds anything; an empty set costs no query.
        $documents = $this->documents->describe(
            \array_map(static fn (BankAccountEvidence $link): string => $link->documentId(), $links),
        );
        $listed = [];

        foreach ($links as $link) {
            $document = $documents[$link->documentId()] ?? null;

            if (null === $document) {
                continue;
            }

            $listed[] = new ListedEvidence(
                $link->id(),
                $link->documentId(),
                $link->kind(),
                $link->attachedAt(),
                $document->name,
                $document->state,
                $document->digest,
                $document->sizeBytes,
            );
        }

        return $listed;
    }
}
