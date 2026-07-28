<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Messenger;

use RuntimeException;
use Symfony\Component\Messenger\Envelope;

/**
 * The outbox as a test reads it: a fixed set of logical queue names, addressed by name and never by SQL
 * on a concrete table, so a Doctrine→Redis swap touches neither features nor contexts.
 *
 * Under `framework.test: true` the `async`/`failed` transports are the `in-memory://?serialize=true`
 * double. This reads their pending queue, so a count drains to 0 once a consume acks the message and a
 * scenario can assert the full publish→consume→ack cycle. How a name becomes a transport, and how much
 * of a queue a read returns, belong to {@see MessengerTransports}.
 *
 * A plain support class rather than context methods: exercisable without the Behat runtime, and a
 * context that asserts on the outbox needs no service container of its own.
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

    public function __construct(private MessengerTransports $transports)
    {
    }

    /**
     * The in-memory transport survives the request and would otherwise leak across scenarios.
     */
    public function reset(): void
    {
        foreach (self::RESETTABLE_QUEUES as $queueName) {
            $this->transports->reset($queueName);
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
            foreach ($this->transports->pending($queueName) as $envelope) {
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
        $this->transports->reject($message['queue'], $message['envelope']);
    }
}
