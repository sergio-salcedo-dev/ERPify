<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * GDPR "right to erasure" for an identity subject, run as one chained operation through {@see FulfilIdentityErasure}:
 * it hard-deletes the user row (the module's PII — email and credential hash) and every pending password-reset
 * token, anonymises every audit row the subject authored, and hard-deletes the subject's sessions — atomically —
 * leaving `GDPR_SUBJECT_ERASED` and `GDPR_ERASURE_EXECUTED` security entries as the compliance record. Because it
 * shares that use case with the identity console, the CLI also enforces the ≥1-active-administrator guard (erasing
 * the last active admin fails). The `system` actor it runs as carries no id, so the self-erasure refusal never
 * applies. The additive `audit:gdpr:erase` command remains for anonymising an actor's trail without erasing the
 * identity.
 */
#[AsCommand(
    name: 'identity:gdpr:erase-subject',
    description: "Irreversibly erase an identity subject's data and audit trail (GDPR right to erasure)",
)]
final class EraseIdentitySubjectCommand extends Command
{
    public function __construct(
        private readonly FulfilIdentityErasure $eraser,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('user-id', InputArgument::REQUIRED, 'The user id (UUID) to erase')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the target without mutating anything')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command hard-deletes an identity (its email and credential
                hash) together with every pending password-reset token, anonymises every audit row the subject
                authored and hard-deletes its sessions — atomically — so no <comment>user_id</comment> linkage,
                recovery artefact or residual session PII outlives the subject. The erasure self-audits as
                <comment>GDPR_SUBJECT_ERASED</comment> and <comment>GDPR_ERASURE_EXECUTED</comment> security
                entries, and is refused if the subject is the last active administrator.

                  <info>php %command.full_name% <user-id> --dry-run</info>
                  <info>php %command.full_name% <user-id></info>
                HELP)
        ;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = $input->getArgument('user-id');

        if (!\is_string($userId) || !Uuid::isValid($userId)) {
            $io->error('The user-id must be a valid UUID.');

            return Command::INVALID;
        }

        $io->writeln(\sprintf('Identity subject: %s', $userId));

        if (!$this->shouldProceed($io, $input)) {
            return Command::SUCCESS;
        }

        return $this->eraseAndReport($io, $userId);
    }

    /**
     * Pre-flight guards that stop before any mutation: a dry run reports the target only, and a real run
     * needs an explicit confirmation (or --force). Either short-circuit leaves the subject untouched.
     */
    private function shouldProceed(SymfonyStyle $io, InputInterface $input): bool
    {
        if (true === $input->getOption('dry-run')) {
            $io->note('Dry run: nothing was erased.');

            return false;
        }

        if (
            true !== $input->getOption('force')
            && !$io->confirm(
                'Irreversibly erase this identity (removes the user and its reset tokens, anonymises its audit '
                . 'trail and drops its sessions)?',
                false,
            )
        ) {
            $io->warning('Aborted — nothing was erased.');

            return false;
        }

        return true;
    }

    private function eraseAndReport(SymfonyStyle $io, string $userId): int
    {
        try {
            $result = $this->eraser->execute($userId);
        } catch (Throwable $throwable) {
            $io->error(\sprintf('Erasure failed: %s', $throwable->getMessage()));

            return Command::FAILURE;
        }

        if (!$result->erasedAnything()) {
            $io->warning(
                'Nothing to erase — the subject has no live identity, pending reset tokens, audit trail or sessions.',
            );

            return Command::SUCCESS;
        }

        $io->success(\sprintf(
            'Erased subject %s (identity removed: %s, reset tokens deleted: %d, audit rows anonymised: %d, '
            . 'sessions removed: %d).',
            $userId,
            $result->identityErased ? 'yes' : 'no',
            $result->resetTokensDeleted,
            $result->anonymizedAuditRows,
            $result->sessionsDeleted,
        ));

        return Command::SUCCESS;
    }
}
