<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\ReconcileErasedSubjectReferences;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifies the resource axis of GDPR erasure: no identity may be gone from `identity_user` while the audit
 * trail still names it by its real id. Exits non-zero on divergence, so it serves an operator on demand and
 * a cron / monitoring check alike — the sibling of `audit:gdpr:reconcile-erasures`, which does the same for
 * crypto-shredding evidence.
 *
 * A finding means some erasure path anonymised the actor axis and not the resource axis, leaving the
 * subject's real id in the trail. The repair is re-running `identity:gdpr:erase-subject` for that id: the
 * anonymisation is idempotent, so a re-run over an already-clean subject costs nothing.
 */
#[AsCommand(
    name: 'identity:gdpr:reconcile-subject-references',
    description: 'Verify no erased identity is still named by its real id in the audit trail',
)]
final class ReconcileErasedSubjectReferencesCommand extends Command
{
    public function __construct(
        private readonly ReconcileErasedSubjectReferences $reconciler,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dangling = $this->reconciler->unreconciledSubjectIds();

        if ([] === $dangling) {
            $io->success('No erased identity is still named by its real id in the audit trail.');

            return Command::SUCCESS;
        }

        $io->error(\sprintf(
            '%d erased identity(ies) still named in the trail — the resource axis was not anonymised:',
            \count($dangling),
        ));
        $io->listing($dangling);
        $io->note('Repair with `identity:gdpr:erase-subject <id>` — the anonymisation is idempotent.');

        return Command::FAILURE;
    }
}
