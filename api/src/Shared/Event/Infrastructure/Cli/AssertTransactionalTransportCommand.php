<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Cli;

use Erpify\Shared\Event\Infrastructure\Messenger\AsyncTransportValidator;
use Erpify\Shared\Event\Infrastructure\Messenger\RequireTransactionalPublishMiddleware;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Refuses to let a process boot when `async` would publish outside the caller's transaction.
 *
 * The question itself ("is `async` the Doctrine transport on the connection the use case writes through?") is
 * {@see AsyncTransportValidator}'s, shared with {@see RequireTransactionalPublishMiddleware}, which asks it
 * again at every publication; this command is the early, loud half that fails the process at start.
 *
 * Run from the container entrypoint, so the `php`, `messenger_worker` and `scheduler_worker` services each
 * answer for the DSN they were started with, and so does any one-off `docker compose run … bin/console`: a
 * command that publishes an event inside a transaction relies on the same guarantee. Under `APP_ENV=test`
 * `async` is an in-memory double that the test service configuration admits to the validator, so the command
 * passes there too.
 */
#[AsCommand(
    name: 'event:transport:assert-transactional',
    description: 'Fail unless the async transport is the Doctrine transport on the application connection',
)]
final class AssertTransactionalTransportCommand extends Command
{
    public function __construct(private readonly AsyncTransportValidator $validator)
    {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $problem = $this->validator->problem();

        if (null !== $problem) {
            $io->error([
                $problem,
                'Domain events routed to "async" would be published outside the transaction that produced them: '
                . 'a worker could act on work that never committed, or the message could be lost for work that did.',
                'Fix MESSENGER_TRANSPORT_DSN for this service '
                . '(it must be a doctrine:// DSN on the connection the application writes through).',
            ]);

            return Command::FAILURE;
        }

        $io->success('"async" is the Doctrine transport on the application connection.');

        return Command::SUCCESS;
    }
}
