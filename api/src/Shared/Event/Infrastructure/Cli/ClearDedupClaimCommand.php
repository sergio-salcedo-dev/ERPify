<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Cli;

use Erpify\Shared\Event\Application\DomainEventHandlerDeduplicator;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Operational escape hatch for the dead-letter replay footgun: when a handler's dedup claim is left
 * dangling (a failed release, or a crash between claim and side effect), a plain
 * `messenger:failed:retry` short-circuits — the handler sees the claim, returns without acting, and
 * Messenger drops the message as handled. The safe order is always *clear the claim, then retry*; this
 * command performs the clear. See docs/adr/domain-event-handler-idempotency.md.
 */
#[AsCommand(
    name: 'event:dedup:clear',
    description: 'Clear a domain-event handler dedup claim so a failed message can be re-sent on retry',
)]
final class ClearDedupClaimCommand extends Command
{
    public function __construct(
        private readonly DomainEventHandlerDeduplicator $deduplicator,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('eventId', InputArgument::REQUIRED, 'The stuck event id (from messenger:failed:status)')
            ->addArgument('handler', InputArgument::REQUIRED, 'The handler FQCN that holds the claim')
            ->addUsage('0190e2c0-... "Erpify\Backoffice\Bank\Infrastructure\Messenger\SendEmailOnBankChanged"')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command releases a single <comment>(eventId, handler)</comment>
                dedup claim from the <comment>handled_domain_event</comment> table, re-opening the retry path.

                Safe replay order (never retry first):

                  <info>php %command.full_name% <eventId> <handlerFqcn></info>
                  <info>php bin/console messenger:failed:retry <messageId></info>

                Get the event id from <info>messenger:failed:status</info>.
                HELP)
        ;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $eventId = $input->getArgument('eventId');
        $handler = $input->getArgument('handler');

        if (!\is_string($eventId) || '' === $eventId || !\is_string($handler) || '' === $handler) {
            $io->error('Both eventId and handler are required.');

            return Command::INVALID;
        }

        if (0 === $this->deduplicator->release($eventId, $handler)) {
            $io->warning(\sprintf(
                'No dedup claim found for event "%s" / handler "%s" — nothing to clear. '
                . 'Verify the id and handler FQCN against messenger:failed:status before retrying.',
                $eventId,
                $handler,
            ));

            return Command::SUCCESS;
        }

        $io->success(\sprintf(
            'Cleared dedup claim for event "%s" / handler "%s". Now run: messenger:failed:retry',
            $eventId,
            $handler,
        ));

        return Command::SUCCESS;
    }
}
