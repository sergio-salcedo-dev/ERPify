<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountCounter;

/**
 * Single read-side seam that resolves how many accounts reference a bank and hangs the result on the
 * {@see Bank} read-projection. It is the ONLY collaborator that consumes the cross-context
 * {@see BankAccountCounter} port — {@see BankSearcher}, {@see BankDetailFinder} and the realtime
 * publisher all compose their counts through here instead of each reaching across the module
 * boundary. Two payoffs: the cross-context surface stays a single allowlisted import, and a future
 * switch to a denormalized source (a `bank.account_count` column or an event-fed projection) is a
 * change to this class alone — the consumers and the `Page<Bank>` / `Bank.accountCount` contract
 * stay put.
 */
final readonly class BankAccountCountEnricher
{
    public function __construct(private BankAccountCounter $accountCounts)
    {
    }

    /**
     * Assign every bank its account count, resolving the whole batch in one port call (no N+1 across
     * the page). Banks absent from the count map have no accounts and resolve to 0. The banks are
     * mutated in place.
     *
     * @param list<Bank> $banks
     */
    public function enrichAll(array $banks): void
    {
        $bankIds = [];

        foreach ($banks as $bank) {
            $id = $bank->getId();

            if (null !== $id) {
                $bankIds[] = $id;
            }
        }

        $counts = $this->accountCounts->countsByBankIds($bankIds);

        foreach ($banks as $bank) {
            $id = $bank->getId();
            $bank->assignAccountCount(null !== $id ? ($counts[$id] ?? 0) : 0);
        }
    }

    /**
     * Single-bank convenience over {@see enrichAll()} for the detail projection. The count follows
     * the hydrated aggregate id — DB-canonical (lower-case) — not the route casing: an upper-case but
     * valid UUID in the URL still matches the row, so keying off the entity's id keeps the count
     * correct where keying off the raw route string would miss and report 0.
     */
    public function enrich(Bank $bank): void
    {
        $this->enrichAll([$bank]);
    }

    /**
     * Raw count for one bank id, for callers that compose a payload from event primitives rather than
     * from a hydrated {@see Bank} (the realtime publisher). An id with no accounts resolves to 0.
     */
    public function countFor(string $bankId): int
    {
        return $this->accountCounts->countsByBankIds([$bankId])[$bankId] ?? 0;
    }
}
