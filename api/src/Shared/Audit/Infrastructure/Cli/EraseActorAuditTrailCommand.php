<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Cli;

use Erpify\Shared\Audit\Application\AuditActorAnonymiser;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
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
 * GDPR "right to erasure" entry point: anonymises one subject's audit trail when an operator handles a
 * data-subject request. A console command rather than an HTTP route because there is no authentication
 * yet — an unauthenticated erasure endpoint would be a footgun, so the trigger stays operator-only until
 * the identity context can protect it. Synchronous: the scope is one subject's rows, not a table sweep.
 *
 * The erasure is itself recorded as a `security` audit entry carrying the resulting pseudonym (never the
 * original id), so the act of forgetting is provable for compliance without re-identifying the person.
 */
#[AsCommand(
    name: 'audit:gdpr:erase',
    description: "Irreversibly anonymise one actor's audit trail (GDPR right to erasure)",
)]
final class EraseActorAuditTrailCommand extends Command
{
    private const string ERASURE_ACTION = 'GDPR_ERASURE_EXECUTED';

    public function __construct(
        private readonly AuditActorAnonymiser $anonymiser,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('actor-id', InputArgument::REQUIRED, 'The actor id (UUID) whose audit trail to erase')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the matching row count without mutating')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command anonymises every <comment>audit_log</comment> row of
                one actor: <comment>actor_id</comment> is overwritten with a single fresh random UUID and
                <comment>ip</comment>/<comment>user_agent</comment> are redacted. Rows are never deleted —
                the security trail survives, it just stops being attributable to the person.

                <comment>This is the ACTOR axis only, and it is not a complete GDPR erasure for a person.</comment>
                Rows that <options=bold>name</> the subject in <comment>resource_id</comment> are untouched
                here, so running this alone on someone who also appears as a resource leaves their real id
                beside the fresh pseudonym. Use <info>identity:gdpr:erase-subject</info> for an identity —
                it erases both axes in one transaction.

                  <info>php %command.full_name% <actor-id> --dry-run</info>
                  <info>php %command.full_name% <actor-id></info>
                HELP)
        ;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $actorId = $input->getArgument('actor-id');

        if (!\is_string($actorId) || !Uuid::isValid($actorId)) {
            $io->error('The actor-id must be a valid UUID.');

            return Command::INVALID;
        }

        $matched = $this->anonymiser->countFor($actorId);
        $io->writeln(\sprintf('Actor:        %s', $actorId));
        $io->writeln(\sprintf('Rows matched: %d', $matched));

        if (0 === $matched) {
            $io->warning('No audit rows for that actor — nothing to erase.');

            return Command::SUCCESS;
        }

        if (true === $input->getOption('dry-run')) {
            $io->note('Dry run: no rows were changed.');

            return Command::SUCCESS;
        }

        if (
            true !== $input->getOption('force')
            && !$io->confirm(\sprintf('Irreversibly anonymise %d row(s)?', $matched), false)
        ) {
            $io->warning('Aborted — no rows were changed.');

            return Command::SUCCESS;
        }

        return $this->eraseAndReport($io, $actorId);
    }

    private function eraseAndReport(SymfonyStyle $io, string $actorId): int
    {
        $result = $this->anonymiser->anonymise($actorId);

        try {
            $this->auditLogger->log(self::ERASURE_ACTION, AuditLevel::SECURITY, null, [
                'anonymized_actor_id' => $result->pseudonym,
                'affected_rows' => $result->affectedRows,
            ]);
        } catch (Throwable $throwable) {
            // The anonymisation already committed and is irreversible; only the compliance self-audit
            // failed. The original id now matches nothing, so a naive re-run would falsely report
            // "nothing to erase" — surface the gap so the operator records this erasure out of band.
            $io->error([
                \sprintf(
                    'Anonymised %d row(s) to actor id %s, but recording the %s audit entry failed: %s',
                    $result->affectedRows,
                    $result->pseudonym,
                    self::ERASURE_ACTION,
                    $throwable->getMessage(),
                ),
                'The erasure is done and irreversible — do not re-run with the original id. '
                . 'Record this erasure manually for compliance.',
            ]);

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            'Erased %d row(s). New anonymised actor id: %s',
            $result->affectedRows,
            $result->pseudonym,
        ));

        return Command::SUCCESS;
    }
}
