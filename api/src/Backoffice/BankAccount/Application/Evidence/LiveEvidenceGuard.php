<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Evidence;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountHasLiveEvidence;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountEvidenceRepository;
use Erpify\Documents\Document\Application\Evidence\DescribeEvidenceDocuments;

/**
 * Releases an account's evidence links before the account row goes, and only when every linked document is erased.
 * Shared by the business deletion and the subject's erasure, which answer the same refusal: neither erases a document
 * itself, because doing so from the console would need an erasure path with no voter and no actor, and whether a
 * piece of evidence must outlive its subject is decided per document, not by a cascade.
 *
 * Contract: call it inside the transaction that removes the account, after the account row is locked `FOR UPDATE`.
 * That lock is what keeps an attach (which takes the same lock) from adding a link between this read and the removal.
 * A document erased concurrently only turns a refusal into a success on the next call; nothing here can be made
 * wrong by it, since an erasure never brings a document back.
 */
final readonly class LiveEvidenceGuard
{
    public function __construct(
        private BankAccountEvidenceRepository $evidence,
        private DescribeEvidenceDocuments $documents,
    ) {
    }

    /**
     * @throws BankAccountHasLiveEvidence when a linked document is not erased (409); nothing is removed
     */
    public function releaseAll(string $bankAccountId): void
    {
        $links = $this->evidence->findByBankAccountId($bankAccountId);

        if ([] === $links) {
            return;
        }

        $described = $this->documents->describe(
            \array_map(static fn (BankAccountEvidence $link): string => $link->documentId(), $links),
        );

        $live = 0;

        foreach ($links as $link) {
            // A link whose document has no row cannot exist (the foreign key), so an absent one counts as live:
            // refusing is the side that cannot lose evidence.
            if (!($described[$link->documentId()] ?? null)?->erased) {
                ++$live;
            }
        }

        if (0 < $live) {
            throw BankAccountHasLiveEvidence::withCount($live);
        }

        $this->evidence->removeAll($links);
    }
}
