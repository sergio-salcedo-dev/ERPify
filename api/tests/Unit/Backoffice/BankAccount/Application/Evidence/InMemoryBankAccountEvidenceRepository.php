<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application\Evidence;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountEvidenceAlreadyAttached;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountEvidenceRepository;
use Override;

/**
 * In-memory {@see BankAccountEvidenceRepository} that refuses a duplicate pair as the unique constraint does and
 * records what was removed.
 *
 * @internal
 */
final class InMemoryBankAccountEvidenceRepository implements BankAccountEvidenceRepository
{
    /** @var list<BankAccountEvidence> */
    public array $links = [];

    /** @var list<BankAccountEvidence> */
    public array $removed = [];

    #[Override]
    public function add(BankAccountEvidence $evidence): void
    {
        if ($this->holds($evidence->bankAccountId(), $evidence->documentId())) {
            throw BankAccountEvidenceAlreadyAttached::of($evidence->bankAccountId(), $evidence->documentId());
        }

        $this->links[] = $evidence;
    }

    #[Override]
    public function holds(string $bankAccountId, string $documentId): bool
    {
        foreach ($this->links as $link) {
            if ($link->bankAccountId() === $bankAccountId && $link->documentId() === $documentId) {
                return true;
            }
        }

        return false;
    }

    #[Override]
    public function ofBankAccount(string $bankAccountId): array
    {
        return \array_values(\array_filter(
            $this->links,
            static fn (BankAccountEvidence $link): bool => $link->bankAccountId() === $bankAccountId,
        ));
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
