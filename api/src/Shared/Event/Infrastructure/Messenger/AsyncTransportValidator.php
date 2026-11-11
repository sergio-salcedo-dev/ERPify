<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Throwable;

/**
 * Answers one question about the running process: does `async` keep its after-commit guarantee?
 *
 * Every event routed to `async` gets that guarantee from one fact: the transport is the Doctrine one, over the
 * very DBAL connection the use case writes through, so the `INSERT` into `messenger_messages` lives in the
 * caller's transaction and a rollback takes it along. `MESSENGER_TRANSPORT_DSN` is a deployment value
 * (`compose.yaml` and `compose.prod.yaml` interpolate it, per service), so no repository file can pin it — the
 * resolved value can only be asked of the process that will use it. The container entrypoint asks once at boot
 * ({@see \Erpify\Shared\Event\Infrastructure\Cli\AssertTransactionalTransportCommand}) and
 * {@see RequireTransactionalPublishMiddleware} asks at every publication, so a process that skipped the
 * entrypoint is refused where it publishes.
 *
 * The transport is built and inspected, never sent through: a probe message pushed through a transport
 * resolved to a broker, or to a second database connection, would itself be the leak. `configureSchema()` is
 * the public question "do you live on this connection?" — with the same-database fallback forced to `false`
 * it adds the queue table to the schema only when the transport's connection IS the one given.
 *
 * The answer is a property of the process (its DSN is fixed at start), so it is computed once and kept.
 * `$acceptedTransportClasses` names transports admitted without a connection check; production declares none,
 * and the test environment admits the in-memory double through service configuration, never by reading the
 * environment here.
 */
final class AsyncTransportValidator
{
    public const string TRANSPORT = 'async';

    private bool $resolved = false;

    private ?string $problem = null;

    /**
     * @param ServiceLocator<mixed> $transports
     * @param list<class-string>    $acceptedTransportClasses
     */
    public function __construct(
        #[Autowire(service: 'messenger.receiver_locator')]
        private readonly ServiceLocator $transports,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(param: 'erpify.messenger.accepted_async_transport_classes')]
        private readonly array $acceptedTransportClasses = [],
    ) {
    }

    /**
     * The reason `async` is not transactional, or null when it is.
     */
    public function problem(): ?string
    {
        if (!$this->resolved) {
            $this->problem = $this->inspect();
            $this->resolved = true;
        }

        return $this->problem;
    }

    private function inspect(): ?string
    {
        try {
            $transport = $this->transports->get(self::TRANSPORT);
        } catch (Throwable $throwable) {
            // The class only: a transport factory's message can quote the DSN, and a DSN carries credentials
            // into a log no erasure or rotation path owns.
            return \sprintf('The "%s" transport cannot be built (%s).', self::TRANSPORT, $throwable::class);
        }

        foreach ($this->acceptedTransportClasses as $acceptedClass) {
            if ($transport instanceof $acceptedClass) {
                return null;
            }
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

        return [] === $schema->getTables()
            ? \sprintf(
                'The "%s" transport is a Doctrine transport on a different connection '
                . 'than the application writes through.',
                self::TRANSPORT,
            )
            : null;
    }
}
