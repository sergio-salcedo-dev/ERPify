<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Cli;

use Erpify\Documents\Document\Application\Erasure\ErasureLedger;
use Erpify\Documents\Document\Application\Erasure\ErasureOverrideSwitch;
use Erpify\Documents\Document\Application\Erasure\ErasureProgress;
use Erpify\Documents\Document\Application\Erasure\LedgerEntry;
use Erpify\Documents\Document\Application\Erasure\LedgerUnreadable;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Clears the erasure ledger's override, once a person has decided to, and only when the database agrees the ledger has
 * been replayed. **Nothing clears an override by itself, whatever its reason**: the checks below are necessary and
 * never sufficient (for a new, empty repository they hold vacuously, and would reopen reads over keys nobody replayed),
 * so the decision is this command's invocation.
 *
 * The order is the point. The set of overrides it may clear is read FIRST; then the ledger is read in full and the
 * database is asked whether every scope in it is finished and no row of it is left un-erased; only then are exactly
 * those ids cleared (`WHERE id IN (…)`, never an unbounded `UPDATE`). An override set while this ran is not in the
 * snapshot, so it stays standing unchecked. It does not replay anything itself — the restore command and the completion
 * sweep are the only holders of that service — it verifies that they did.
 *
 * It takes no argument and no option, and prints the `record_ref` of each override it cleared: the reference the
 * operator wrote in the off-host log, so the log entry can be closed. Non-zero means nothing was cleared.
 */
#[AsCommand(
    name: 'documents:erasure:clear-override',
    description: 'Clear the erasure ledger override after a full, clean replay (takes no argument)',
)]
final class ClearErasureLedgerOverrideCommand extends Command
{
    public function __construct(
        private readonly ErasureOverrideSwitch $overrideSwitch,
        private readonly ErasureLedger $ledger,
        private readonly ErasureProgress $progress,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $seen = $this->overrideSwitch->unclearedIds();

        if ([] === $seen) {
            $io->success('No erasure ledger override is set.');

            return Command::SUCCESS;
        }

        $scopeIds = $this->ledgerScopeIds();

        if (null === $scopeIds || !$this->everyScopeErased($scopeIds)) {
            $io->error(null === $scopeIds
                ? 'The erasure ledger cannot be read in full. Nothing was cleared.'
                : 'The database still holds a scope of the ledger that is not erased. Nothing was cleared.');

            return Command::FAILURE;
        }

        $references = $this->overrideSwitch->clear($seen);

        $io->success(\sprintf(
            'Cleared %d override(s) after checking %d ledger scope(s). References:',
            \count($references),
            \count($scopeIds),
        ));

        foreach ($references as $reference) {
            $io->writeln($reference);
        }

        return Command::SUCCESS;
    }

    /**
     * Every scope id the ledger holds, or null when it cannot be read in full: a partial read would clear an override
     * over scopes nobody checked.
     *
     * @return list<string>|null
     */
    private function ledgerScopeIds(): ?array
    {
        try {
            $this->ledger->verifyReadableInFull();

            return \array_map(static fn (LedgerEntry $entry): string => $entry->scopeId, $this->ledger->entries());
        } catch (LedgerUnreadable) {
            return null;
        }
    }

    /**
     * @param list<string> $scopeIds
     */
    private function everyScopeErased(array $scopeIds): bool
    {
        return [] === $this->progress->unfinished($scopeIds) && 0 === $this->progress->notErasedAmong($scopeIds);
    }
}
