<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Messenger;

use RuntimeException;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * Resolves a Messenger transport from its logical name and hands back only what the caller can use:
 * a receiver for a {@see \Symfony\Component\Messenger\Worker}, or the in-memory double's queues when a
 * scenario needs to look inside one.
 *
 * The single place that knows how a name becomes a transport, so the suite has one definition of
 * "inspectable" and one failure message for a transport that isn't. It also owns the fetch size,
 * which is the reason it exists rather than each context resolving its own: `InMemoryTransport::get()`
 * takes a size defaulting to 1 and stops there, so a caller that omits it reads at most one message and
 * every count above one silently passes for the wrong reason. Held in one place, a future change to
 * that upstream signature is one fix rather than one per caller.
 *
 * It refuses rather than degrades: a name that resolves to nothing, or to a transport of the wrong
 * kind, throws instead of yielding an empty read that would satisfy a zero-assertion.
 */
final readonly class MessengerTransports
{
    /**
     * Read the queue whole. See the class docblock for why this may never be left implicit.
     */
    private const int WHOLE_QUEUE = PHP_INT_MAX;

    public function __construct(private Container $container)
    {
    }

    /**
     * Any receiver will do — a Worker consumes through the interface, not the double.
     */
    public function receiver(string $name): ReceiverInterface
    {
        $found = $this->service($name);

        if (!$found instanceof ReceiverInterface) {
            throw new RuntimeException($this->refusal($name, ReceiverInterface::class, $found));
        }

        return $found;
    }

    /**
     * Every envelope still queued. Non-destructive, so a post-consume read sees the drained 0.
     *
     * The size goes in positionally because upstream does not declare the parameter at all: its
     * signature carries `$fetchSize` only inside a comment and reads the value through
     * `func_get_arg(0)`. That is invisible to a reader and to static analysis alike, which is how its
     * truncating default of 1 went unnoticed here once already.
     *
     * @return list<Envelope>
     */
    public function pending(string $name): array
    {
        $envelopes = [];

        foreach ($this->inMemory($name)->get(self::WHOLE_QUEUE) as $envelope) {
            $envelopes[] = $envelope;
        }

        return $envelopes;
    }

    /**
     * The retained sent log, which survives a consume that has already drained {@see pending()}.
     *
     * @return list<Envelope>
     */
    public function sent(string $name): array
    {
        return \array_values($this->inMemory($name)->getSent());
    }

    public function reset(string $name): void
    {
        $this->inMemory($name)->reset();
    }

    public function reject(string $name, Envelope $envelope): void
    {
        $this->inMemory($name)->reject($envelope);
    }

    private function inMemory(string $name): InMemoryTransport
    {
        $found = $this->service($name);

        if (!$found instanceof InMemoryTransport) {
            throw new RuntimeException($this->refusal($name, InMemoryTransport::class, $found));
        }

        return $found;
    }

    private function service(string $name): ?object
    {
        $serviceId = $this->serviceId($name);

        return $this->container->has($serviceId) ? $this->container->get($serviceId) : null;
    }

    private function serviceId(string $name): string
    {
        return 'messenger.transport.' . $name;
    }

    private function refusal(string $name, string $expected, ?object $found): string
    {
        return \sprintf(
            'Transport "%s" must resolve to a %s; service %s is %s.',
            $name,
            $expected,
            $this->serviceId($name),
            null === $found ? 'not registered' : \get_debug_type($found),
        );
    }
}
