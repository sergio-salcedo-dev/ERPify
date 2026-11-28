<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Exception\EvidenceAlreadyAttached;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountEvidenceRepository;
use Override;

/**
 * In-memory {@see BankAccountEvidenceRepository} that refuses a second link of one document the way the table's UNIQUE
 * does, and records what was removed.
 *
 * @internal
 */
final class InMemoryBankAccountEvidenceRepository implements BankAccountEvidenceRepository
{
    /** @var list<BankAccountEvidence> */
    public array $links = [];

    /** @var list<BankAccountEvidence> */
    public array $removed = [];

    public function __construct(BankAccountEvidence ...$links)
    {
        $this->links = \array_values($links);
    }

    #[Override]
    public function save(BankAccountEvidence $evidence): void
    {
        if ($this->findByDocumentId($evidence->documentId()) instanceof BankAccountEvidence) {
            throw EvidenceAlreadyAttached::create();
        }

        $this->links[] = $evidence;
    }

    #[Override]
    public function findByBankAccountId(string $bankAccountId): array
    {
        return \array_values(\array_filter(
            $this->links,
            static fn (BankAccountEvidence $link): bool => $link->bankAccountId() === \strtolower($bankAccountId),
        ));
    }

    #[Override]
    public function findByDocumentId(string $documentId): ?BankAccountEvidence
    {
        foreach ($this->links as $link) {
            if ($link->documentId() === \strtolower($documentId)) {
                return $link;
            }
        }

        return null;
    }

    #[Override]
    public function removeAll(array $links): void
    {
        foreach ($links as $link) {
            $this->removed[] = $link;
            $this->links = \array_values(\array_filter(
                $this->links,
                static fn (BankAccountEvidence $kept): bool => $kept !== $link,
            ));
        }
    }
}
