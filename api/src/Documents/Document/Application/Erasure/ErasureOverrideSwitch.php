<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

use Erpify\Documents\Document\Domain\Erasure\LedgerOverrideReason;

/**
 * Sets and clears the erasure ledger's override. The read side only asks
 * ({@see \Erpify\Documents\Document\Application\Read\ErasureLedgerOverride}); this is the narrow write side, so the
 * routes that ask cannot also set it.
 *
 * **Nothing clears an override by itself, whatever its reason.** The preconditions the clearing command checks — the
 * ledger read in full, every scope of it finished, no row of it left un-erased — are necessary and never sufficient:
 * for a new empty repository they hold vacuously and would reopen reads over keys nobody replayed. A person runs the
 * command.
 */
interface ErasureOverrideSwitch
{
    /**
     * Sets the flag and returns the random `record_ref` the operator writes in the off-host log. Holds no name.
     */
    public function activate(LedgerOverrideReason $reason): string;

    /**
     * The ids of every uncleared override, ascending: the set a clearing run may later clear, taken BEFORE it reads
     * the ledger, so an override set while it works is not among them.
     *
     * @return list<string>
     */
    public function unclearedIds(): array;

    /**
     * Clears exactly the given overrides that are still uncleared (never an unbounded `UPDATE`) and returns the
     * `record_ref` of each one this call cleared.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function clear(array $ids): array;

    /**
     * How many overrides stand and how long the oldest has, or null when none does: what the completion tick reports so
     * a standing 410 is never silent.
     */
    public function standing(): ?StandingOverride;
}
