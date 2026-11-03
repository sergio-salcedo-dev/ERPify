<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Cli;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Throwable;

/**
 * Refuses to let a process boot when `async` would publish outside the caller's transaction.
 *
 * Every event routed to `async` gets its after-commit guarantee from one fact: the transport is the Doctrine
 * one, over the very DBAL connection the use case writes through, so the `INSERT` into `messenger_messages`
 * lives in the caller's transaction and a rollback takes it along. `MESSENGER_TRANSPORT_DSN` is a deployment
 * value (`compose.yaml` and `compose.prod.yaml` interpolate it, per service), so no repository file can pin
 * it — the resolved value can only be asked of the process that will use it.
 *
 * The transport is built and inspected, never sent through: a probe message pushed through a transport
 * resolved to a broker, or to a second database connection, would itself be the leak. `configureSchema()` is
 * the public question "do you live on this connection?" — with the same-database fallback forced to `false`
 * it adds the queue table to the schema only when the transport's connection IS the one given.
 *
 * Run from the container entrypoint, so the `php`, `messenger_worker` and `scheduler_worker` services each
 * answer for the DSN they were started with, and so does any one-off `docker compose run … bin/console`: a
 * command that publishes an event inside a transaction relies on the same guarantee. Under `APP_ENV=test`
 * `async` is an in-memory double by design and this command reports it as a failure; the test lanes
 * `exec` into a running container and never pass through the entrypoint.
 */
#[AsCommand(
    name: 'event:transport:assert-transactional',
    description: 'Fail unless the async transport is the Doctrine transport on the application connection',
)]
final class AssertTransactionalTransportCommand extends Command
{
    private const string TRANSPORT = 'async';

    /**
     * @param ServiceLocator<mixed> $transports
     */
    public function __construct(
        #[Autowire(service: 'messenger.receiver_locator')]
        private readonly ServiceLocator $transports,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $problem = $this->problem();

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

    private function problem(): ?string
    {
        try {
            $transport = $this->transports->get(self::TRANSPORT);
        } catch (Throwable $throwable) {
            // The class only: a transport factory's message can quote the DSN, and a DSN carries credentials
            // into a log no erasure or rotation path owns.
            return \sprintf('The "%s" transport cannot be built (%s).', self::TRANSPORT, $throwable::class);
        }

        if (!$transport instanceof DoctrineTransport) {
            return \sprintf(
                'The "%s" transport resolves to %s, not the Doctrine transport.',
                self::TRANSPORT,
                \get_debug_type($transport),
            );
        }

        $schema = $transport->configureSchema(
            new Schema(),
            $this->entityManager->getConnection(),
            static fn (): bool => false,
        );

        if ([] === $schema->getTables()) {
            return \sprintf(
                'The "%s" transport is a Doctrine transport on a different connection '
                . 'than the application writes through.',
                self::TRANSPORT,
            );
        }

        return null;
    }
}
