<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application\Evidence;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountEvidenceAlreadyAttached;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountEvidenceRepository;
use Override;

/**
 * In-memory {@see BankAccountEvidenceRepository} that refuses a document some account already holds, as the unique
 * constraint does, and records what was removed.
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
        if ($this->isAttached($evidence->documentId())) {
            throw BankAccountEvidenceAlreadyAttached::of($evidence->documentId());
        }

        $this->links[] = $evidence;
    }

    #[Override]
    public function isAttached(string $documentId): bool
    {
        return \array_any(
            $this->links,
            static fn (BankAccountEvidence $link): bool => $link->documentId() === $documentId,
        );
    }

    #[Override]
    public function findOnAccount(string $bankAccountId, string $linkId): ?BankAccountEvidence
    {
        return \array_find(
            $this->links,
            static fn (BankAccountEvidence $link): bool => $link->id() === $linkId
                && $link->bankAccountId() === $bankAccountId,
        );
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
