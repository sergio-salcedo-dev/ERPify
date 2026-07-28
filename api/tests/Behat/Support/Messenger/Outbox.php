<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Messenger;

use RuntimeException;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The outbox as a test reads it: Messenger transports addressed by logical queue name and never by SQL
 * on a concrete table, so a Doctrine→Redis swap touches neither features nor contexts.
 *
 * Under `framework.test: true` the `async`/`failed` transports are the `in-memory://?serialize=true`
 * double. This reads their PENDING queue ({@see InMemoryTransport::get()}, non-destructive — not
 * `getSent()`, which retains acked envelopes), so a count drains to 0 once a consume acks the message
 * and a scenario can assert the full publish→consume→ack cycle.
 *
 * A plain support class rather than context methods: the transport lookup is exercisable without the
 * Behat runtime, and a context that asserts on the outbox needs no service container of its own.
 *
 * It refuses rather than degrades. A queue it cannot inspect would make every assertion above it pass
 * while nothing was ever read — `0 outbox events were created` is satisfied by an unread queue just as
 * well as by an empty one — so an absent or wrongly-typed transport throws.
 */
final readonly class Outbox
{
    /**
     * In-memory transports whose pending queue is the outbox. `sync` (`sync://`) is a `SyncTransport`
     * with no queue at all, so it is absent by design; everything listed here must be inspectable.
     *
     * @var list<string>
     */
    private const array INSPECTABLE_QUEUES = ['async', 'failed'];

    /**
     * Queues drained between scenarios so pending messages don't leak across them. Distinct from
     * {@see INSPECTABLE_QUEUES} so a transport can be drained without becoming an inspectable outbox
     * queue that inflates the domain-event count.
     *
     * @var list<string>
     */
    private const array RESETTABLE_QUEUES = ['async', 'failed'];

    /**
     * InMemoryTransport::get() takes a fetch size that defaults to 1 and stops there. Inspecting an
     * outbox means reading all of it, so the size is passed explicitly — left implicit, every count
     * above one silently reads as one and the assertion passes for the wrong reason.
     */
    private const int WHOLE_QUEUE = PHP_INT_MAX;

    public function __construct(private Container $container)
    {
    }

    /**
     * The in-memory transport survives the request and would otherwise leak across scenarios.
     */
    public function reset(): void
    {
        foreach (self::RESETTABLE_QUEUES as $queueName) {
            $this->transport($queueName)->reset();
        }
    }

    /**
     * Read fresh on each call (cheap, non-destructive), so a post-consume assertion sees the drained 0
     * rather than a stale cached count.
     *
     * @return list<array{queue: string, envelope: Envelope, event: object}>
     */
    public function messages(): array
    {
        $messages = [];

        foreach (self::INSPECTABLE_QUEUES as $queueName) {
            foreach ($this->transport($queueName)->get(self::WHOLE_QUEUE) as $envelope) {
                $messages[] = ['queue' => $queueName, 'envelope' => $envelope, 'event' => $envelope->getMessage()];
            }
        }

        return $messages;
    }

    /**
     * @return list<array{queue: string, envelope: Envelope, event: object}>
     */
    public function messagesOnQueue(string $queueName): array
    {
        // A queue that is not an inspectable outbox queue (a typo, or `sync`) would otherwise count 0,
        // so `0 outbox events … on queue "<typo>"` would pass.
        if (!\in_array($queueName, self::INSPECTABLE_QUEUES, true)) {
            throw new RuntimeException(\sprintf(
                'Queue "%s" is not an inspectable outbox queue; expected one of: %s',
                $queueName,
                \implode(', ', self::INSPECTABLE_QUEUES),
            ));
        }

        return \array_values(\array_filter(
            $this->messages(),
            static fn (array $message): bool => $message['queue'] === $queueName,
        ));
    }

    /**
     * @param array{queue: string, envelope: Envelope, event: object} $message
     */
    public function remove(array $message): void
    {
        $this->transport($message['queue'])->reject($message['envelope']);
    }

    private function transport(string $queueName): InMemoryTransport
    {
        $serviceId = 'messenger.transport.' . $queueName;
        $found = $this->container->has($serviceId) ? $this->container->get($serviceId) : null;

        if (!$found instanceof InMemoryTransport) {
            throw new RuntimeException(\sprintf(
                'Queue "%s" must resolve to an inspectable %s; service %s is %s.',
                $queueName,
                InMemoryTransport::class,
                $serviceId,
                null === $found ? 'not registered' : \get_debug_type($found),
            ));
        }

        return $found;
    }
}
