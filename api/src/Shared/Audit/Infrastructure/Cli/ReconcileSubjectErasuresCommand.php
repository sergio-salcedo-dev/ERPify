<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Cli;

use Erpify\Shared\Audit\Application\SubjectErasureReconciler;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifies GDPR subject-erasure integrity: every destroyed encryption key must have a matching
 * `GDPR_SUBJECT_ERASED` audit entry. Exits non-zero when a divergence exists, so it is usable on demand by
 * an operator and as a cron / monitoring / CI check (the standing "job" the ADR anticipates); the daily
 * schedule logs the same finding.
 */
#[AsCommand(
    name: 'audit:gdpr:reconcile-erasures',
    description: 'Verify every crypto-shredded subject has its GDPR_SUBJECT_ERASED evidence',
)]
final class ReconcileSubjectErasuresCommand extends Command
{
    public function __construct(
        private readonly SubjectErasureReconciler $reconciler,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $unreconciled = $this->reconciler->unreconciledScopes();

        if ([] === $unreconciled) {
            $io->success('Every destroyed encryption key has a matching GDPR_SUBJECT_ERASED entry.');

            return Command::SUCCESS;
        }

        $io->error(\sprintf(
            '%d destroyed key(s) without erasure evidence — integrity divergence:',
            \count($unreconciled),
        ));
        $io->listing($unreconciled);

        return Command::FAILURE;
    }
}
