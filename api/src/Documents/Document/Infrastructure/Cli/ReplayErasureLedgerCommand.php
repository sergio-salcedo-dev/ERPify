<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Cli;

use Erpify\Documents\Document\Application\Erasure\ErasureLedger;
use Erpify\Documents\Document\Application\Erasure\ErasureProgress;
use Erpify\Documents\Document\Application\Erasure\LedgerEntry;
use Erpify\Documents\Document\Application\Erasure\LedgerUnreadable;
use Erpify\Documents\Document\Application\Erasure\ReplayErasure;
use Erpify\Documents\Document\Domain\Erasure\ErasureSystemReason;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The restore step: proves the erasure ledger readable in full, then replays every scope in it over the database a
 * restore just reloaded — the row `Erased`, the key tombstoned, the sealed object queued for deletion, an object under
 * a scope the database never held deleted. It is the only caller of {@see ReplayErasure} besides the completion sweep,
 * and the reason it records is `ledger-replay`.
 *
 * It takes **no argument and no option**: the scope ids come from the ledger, never from argv, which the console's
 * error listener logs whole when a command fails to bind. Exit 0 means the ledger was read in full, every scope was
 * replayed and the database holds no row among them that is not `Erased`; anything else exits non-zero and
 * `restore.prod` keeps the stack in recovery mode. The output carries counts and nothing else.
 */
#[AsCommand(
    name: 'documents:erasure:replay-ledger',
    description: 'Replay the erasure ledger over the restored database (restore step; takes no argument)',
)]
final class ReplayErasureLedgerCommand extends Command
{
    public function __construct(
        private readonly ErasureLedger $ledger,
        private readonly ErasureProgress $progress,
        private readonly ReplayErasure $replayErasure,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->ledger->verifyReadableInFull();
            $entries = $this->ledger->entries();
        } catch (LedgerUnreadable) {
            $io->error('The erasure ledger cannot be read in full. Nothing was replayed.');

            return Command::FAILURE;
        }

        $scopeIds = \array_map(static fn (LedgerEntry $entry): string => $entry->scopeId, $entries);

        try {
            foreach ($scopeIds as $scopeId) {
                $this->replayErasure->replay($scopeId, ErasureSystemReason::LedgerReplay);
            }
        } catch (Throwable $throwable) {
            $io->error(\sprintf(
                'The replay stopped on a failure (%s). The database may be partly replayed.',
                $throwable::class,
            ));

            return Command::FAILURE;
        }

        $notErased = $this->progress->notErasedAmong($scopeIds);

        if ([] !== $this->progress->unfinished($scopeIds) || 0 !== $notErased) {
            $io->error('The replay finished but the database still holds a scope of the ledger that is not erased.');

            return Command::FAILURE;
        }

        $io->success(\sprintf('Replayed %d ledger scope(s); every one is erased.', \count($scopeIds)));

        return Command::SUCCESS;
    }
}
