<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Domain\Event\DomainEvent;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\EventHydrator;
use Erpify\Tests\Behat\Support\Json\Json;
use Erpify\Tests\Behat\Support\PostProcess\JsonToolTrait;
use JsonException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Throwable;

/**
 * Asserts the event-driven *outbox* — the Messenger transport, addressed by logical queue name and
 * never by SQL on a concrete table, so a Doctrine→Redis swap touches neither features nor context.
 *
 * Under `framework.test: true` the `async`/`failed` transports are the `in-memory://?serialize=true`
 * double; this reads their PENDING queue ({@see InMemoryTransport::get()}, non-destructive — not
 * `getSent()`, which retains acked envelopes). The count therefore drains to 0 once a consume acks
 * the message, letting a scenario assert the full publish→consume→ack cycle. `sync` (`sync://`) is a
 * `SyncTransport` with no queue and is never inspectable, so aggregation instanceof-filters it out.
 *
 * The persisted `domain_event` store (an audit log a posteriori, not the outbox) is asserted with the
 * generic {@see EntityManagerContext} steps; nested payload fields are read here, on the pending event.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final class OutboxContext extends AbstractContext
{
    use JsonToolTrait;

    /**
     * In-memory transports whose pending queue is the outbox. `sync` is excluded by design.
     *
     * @var list<string>
     */
    private const array INSPECTABLE_QUEUES = ['async', 'failed'];

    private ?object $selectedEvent = null;

    public function __construct(
        #[Autowire(service: 'test.service_container')]
        private readonly Container $container,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The in-memory transport survives the request and leaks across scenarios otherwise; reset the
     * pending queues (and the selection) so each scenario starts from an empty outbox.
     */
    #[BeforeScenario]
    #[Given('I reset the outbox context')]
    public function reset(): void
    {
        $this->selectedEvent = null;

        foreach (self::INSPECTABLE_QUEUES as $queueName) {
            $this->transport($queueName)?->reset();
        }
    }

    #[Then(':number outbox event was created')]
    #[Then(':number outbox events were created')]
    public function outboxEventsWereCreated(int $number): void
    {
        $count = \count($this->messages());

        self::assertSame(
            $number,
            $count,
            \sprintf('%d outbox events were created, but %d was expected', $count, $number),
        );
    }

    #[Then(':number outbox event was created on the queue :queueName')]
    #[Then(':number outbox events were created on the queue :queueName')]
    public function outboxEventsWereCreatedOnQueue(int $number, string $queueName): void
    {
        $count = \count($this->messagesOnQueue($queueName));

        self::assertSame(
            $number,
            $count,
            \sprintf('%d outbox events were created on queue "%s", but %d was expected', $count, $queueName, $number),
        );
    }

    #[Then('I got the event number :number from the outbox')]
    public function selectEventByNumber(int $number): void
    {
        $this->selectEvent($this->messages(), $number);
    }

    #[Then('I got the event number :number on queue :queueName from the outbox')]
    public function selectEventByNumberOnQueue(int $number, string $queueName): void
    {
        $this->selectEvent($this->messagesOnQueue($queueName), $number);
    }

    /**
     * @param class-string $fullyQualifiedClassName
     */
    #[Then('The outbox event should be of type :fullyQualifiedClassName')]
    public function outboxEventShouldBeOfType(string $fullyQualifiedClassName): void
    {
        self::assertInstanceOf($fullyQualifiedClassName, $this->selectedEvent());
    }

    /**
     * @throws JsonException
     */
    #[Then('The outbox event property :property should be equal to :value')]
    public function outboxEventPropertyShouldBeEqualTo(string $property, string $value): void
    {
        $this->jsonPropertyShouldBeEqualTo($this->selectedEventJson(), $property, $value);
    }

    /**
     * @throws JsonException
     */
    #[Then('The outbox event property :property should be null')]
    public function outboxEventPropertyShouldBeNull(string $property): void
    {
        $this->jsonPropertyShouldBeNull($this->selectedEventJson(), $property);
    }

    /**
     * @throws JsonException
     */
    #[Then('The outbox event property :property should not be null')]
    public function outboxEventPropertyShouldNotBeNull(string $property): void
    {
        $this->jsonPropertyShouldNotBeNull($this->selectedEventJson(), $property);
    }

    /**
     * @throws JsonException
     */
    #[Then('The outbox event property :property should have :count element')]
    #[Then('The outbox event property :property should have :count elements')]
    public function outboxEventPropertyShouldHaveElements(string $property, int $count): void
    {
        $this->jsonPropertyShouldHaveElements($this->selectedEventJson(), $property, $count);
    }

    /**
     * @throws JsonException
     */
    #[Then('The outbox event property :property should exist')]
    public function outboxEventPropertyShouldExist(string $property): void
    {
        $this->jsonPropertyShouldExist($this->selectedEventJson(), $property);
    }

    /**
     * @throws JsonException
     */
    #[Then('The outbox event property :property should not exist')]
    public function outboxEventPropertyShouldNotExist(string $property): void
    {
        $this->jsonPropertyShouldNotExist($this->selectedEventJson(), $property);
    }

    #[Then('there should have been an outbox event created containing:')]
    public function anOutboxEventCreatedContaining(TableNode $table): void
    {
        foreach ($this->messages() as $message) {
            if ($this->eventMatchesTable($message['event'], $table)) {
                return;
            }
        }

        self::fail('No outbox event found containing the expected properties');
    }

    #[Then('there should not have been an outbox event created containing:')]
    public function noOutboxEventCreatedContaining(TableNode $table): void
    {
        foreach ($this->messages() as $message) {
            if ($this->eventMatchesTable($message['event'], $table)) {
                self::fail('An outbox event was found containing the properties that should be absent');
            }
        }
    }

    /**
     * Reconstructs the typed event from JSON via reflection ({@see EventHydrator}) and dispatches it
     * on the default bus inside a transaction, so the persist-domain-event middleware still writes the
     * `domain_event` row and the in-memory transport receives the message.
     *
     * @throws JsonException
     */
    #[Then('I dispatch the :fullyQualifiedClassName outbox event with:')]
    public function dispatchOutboxEvent(string $fullyQualifiedClassName, PyStringNode $jsonPayload): void
    {
        /** @var array<array-key, mixed> $payload */
        $payload = (array) \json_decode($jsonPayload->getRaw(), true, 512, JSON_THROW_ON_ERROR);
        $event = (new EventHydrator())->hydrate($fullyQualifiedClassName, $payload);

        $this->entityManager->wrapInTransaction(function () use ($event): void {
            $this->messageBus->dispatch($event);
        });
    }

    #[Then('I remove event :number from the outbox')]
    public function removeEventByNumber(int $number): void
    {
        $messages = $this->messages();
        $message = $messages[$number - 1] ?? null;

        self::assertNotNull($message, \sprintf('No outbox event number %d found', $number));

        $this->transport($message['queue'])?->reject($message['envelope']);
    }

    /**
     * @param class-string $fullyQualifiedClassName
     */
    #[Then('I remove event of type :fullyQualifiedClassName from the outbox')]
    public function removeEventByType(string $fullyQualifiedClassName): void
    {
        foreach ($this->messages() as $message) {
            if ($message['event'] instanceof $fullyQualifiedClassName) {
                $this->transport($message['queue'])?->reject($message['envelope']);
            }
        }
    }

    #[Then('I print all outbox events')]
    public function printAllOutboxEvents(): void
    {
        foreach ($this->messages() as $index => $message) {
            echo \sprintf('#%d [%s] %s%s', $index + 1, $message['queue'], $this->describe($message['event']), PHP_EOL);
        }
    }

    #[Then('I print last outbox event')]
    public function printLastOutboxEvent(): void
    {
        $messages = $this->messages();
        $last = \end($messages);

        echo false === $last ? 'No outbox events' : $this->describe($last['event']);
    }

    /**
     * @param list<array{queue: string, envelope: Envelope, event: object}> $messages
     */
    private function selectEvent(array $messages, int $number): void
    {
        $message = $messages[$number - 1] ?? null;

        self::assertNotNull($message, \sprintf('No outbox event number %d found', $number));

        $this->selectedEvent = $message['event'];
    }

    private function selectedEvent(): object
    {
        self::assertNotNull($this->selectedEvent, 'No outbox event has been selected');

        return $this->selectedEvent;
    }

    /**
     * @throws JsonException
     */
    private function selectedEventJson(): Json
    {
        return $this->eventToJson($this->selectedEvent());
    }

    /**
     * The event's own canonical payload — stable and getter-independent, unlike Symfony normalization
     * of its private readonly properties.
     *
     * @throws JsonException
     */
    private function eventToJson(object $event): Json
    {
        $data = $event instanceof DomainEvent ? $event->toPrimitives() : $event;

        return new Json(\json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function eventMatchesTable(object $event, TableNode $table): bool
    {
        try {
            $json = $this->eventToJson($event);

            foreach ($table->getRowsHash() as $property => $value) {
                $this->jsonPropertyShouldBeEqualTo($json, $property, $value);
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function describe(object $event): string
    {
        if ($event instanceof DomainEvent) {
            return \sprintf('%s %s', $event::class, \json_encode($event->toPrimitives()) ?: '');
        }

        return $event::class;
    }

    /**
     * @return list<array{queue: string, envelope: Envelope, event: object}>
     */
    private function messagesOnQueue(string $queueName): array
    {
        // Fail loudly on a queue that is not an inspectable in-memory outbox queue (a typo, or `sync`):
        // without this it would silently count 0, so `0 outbox events … on queue "<typo>"` would pass.
        self::assertContains(
            $queueName,
            self::INSPECTABLE_QUEUES,
            \sprintf(
                'Queue "%s" is not an inspectable outbox queue; expected one of: %s',
                $queueName,
                \implode(', ', self::INSPECTABLE_QUEUES),
            ),
        );

        return \array_values(\array_filter(
            $this->messages(),
            static fn (array $message): bool => $message['queue'] === $queueName,
        ));
    }

    /**
     * Reads the pending queue fresh each call (cheap, non-destructive), so a post-consume assertion
     * sees the drained 0 rather than a stale cached count.
     *
     * @return list<array{queue: string, envelope: Envelope, event: object}>
     */
    private function messages(): array
    {
        $messages = [];

        foreach (self::INSPECTABLE_QUEUES as $queueName) {
            $transport = $this->transport($queueName);

            if (!$transport instanceof InMemoryTransport) {
                continue;
            }

            foreach ($transport->get() as $envelope) {
                $messages[] = ['queue' => $queueName, 'envelope' => $envelope, 'event' => $envelope->getMessage()];
            }
        }

        return $messages;
    }

    private function transport(string $queueName): ?InMemoryTransport
    {
        $serviceId = 'messenger.transport.' . $queueName;

        if (!$this->container->has($serviceId)) {
            return null;
        }

        $transport = $this->container->get($serviceId);

        return $transport instanceof InMemoryTransport ? $transport : null;
    }
}
