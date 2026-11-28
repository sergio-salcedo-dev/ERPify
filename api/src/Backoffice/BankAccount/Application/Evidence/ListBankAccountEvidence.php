<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Evidence;

use Erpify\Backoffice\BankAccount\Application\BankAccountFinder;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountEvidenceRepository;
use Erpify\Documents\Document\Application\Evidence\DescribeEvidenceDocuments;

/**
 * Every piece of evidence of one bank account with its document's state and name, oldest first. Constant cost: the
 * account, the links, the documents' rows and ONE keystore query for their names, whatever the count.
 *
 * Unpaged on purpose: an account holds a handful of certificates, and nothing lets one grow without an ADMIN filing
 * each document by hand. A page would buy nothing until a kind of evidence exists that arrives in bulk.
 */
final readonly class ListBankAccountEvidence
{
    public function __construct(
        private BankAccountFinder $accounts,
        private BankAccountEvidenceRepository $evidence,
        private DescribeEvidenceDocuments $documents,
    ) {
    }

    /**
     * @throws BankAccountNotFoundException
     *
     * @return list<AttachedEvidence>
     */
    public function list(string $bankAccountId): array
    {
        $this->accounts->find($bankAccountId);

        $links = $this->evidence->findByBankAccountId($bankAccountId);

        // Through the seam even when there is no link: it is what answers the override's 410, so the listing refuses
        // alike whatever the account holds. An empty set costs no row statement and no keystore query.
        $described = $this->documents->describe(
            \array_map(static fn (BankAccountEvidence $link): string => $link->documentId(), $links),
        );

        return \array_map(
            static fn (BankAccountEvidence $link): AttachedEvidence => new AttachedEvidence(
                $link,
                $described[$link->documentId()] ?? null,
            ),
            $links,
        );
    }
}
