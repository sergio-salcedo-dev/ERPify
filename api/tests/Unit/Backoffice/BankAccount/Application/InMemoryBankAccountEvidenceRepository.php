<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Exception\EvidenceAlreadyAttached;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountEvidenceRepository;
use Override;

/**
 * In-memory {@see BankAccountEvidenceRepository} that refuses a duplicate (account, document) pair the way the table's
 * UNIQUE does, and records what was removed.
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
        if ($this->isAttached($evidence->bankAccountId(), $evidence->documentId())) {
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
    public function isAttached(string $bankAccountId, string $documentId): bool
    {
        return \array_any(
            $this->findByBankAccountId($bankAccountId),
            static fn (BankAccountEvidence $link): bool => $link->documentId() === \strtolower($documentId),
        );
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
