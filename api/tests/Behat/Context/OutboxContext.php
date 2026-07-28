<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\Json\Json;
use Erpify\Tests\Behat\Support\Messenger\Outbox;
use Erpify\Tests\Behat\Support\PostProcess\JsonToolTrait;
use JsonException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Gherkin steps over the event-driven *outbox*. Reading it — which logical queues are inspectable, and
 * how a queue resolves to a transport — belongs to {@see Outbox}; this class turns those reads into
 * assertions and owns the one piece of per-scenario state, the selected event.
 *
 * The permanent `event_store` (the append-only log, not the outbox) is asserted with raw-SQL steps;
 * nested payload fields are read here, on the pending event.
 *
 * The three suppressions below are measured, not inherited: 21 public methods and 27 methods are the
 * 21 Gherkin steps this context owns, and cutting them means splitting the step vocabulary across
 * contexts, which is a change to the feature files' surface rather than a refactor. Coupling sits one
 * over the threshold at 13.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final class OutboxContext extends AbstractContext
{
    use JsonToolTrait;

    private ?object $selectedEvent = null;

    public function __construct(
        private readonly Outbox $outbox,
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

        $this->outbox->reset();
    }

    #[Then(':number outbox event was created')]
    #[Then(':number outbox events were created')]
    public function outboxEventsWereCreated(int $number): void
    {
        $count = \count($this->outbox->messages());

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
        $count = \count($this->outbox->messagesOnQueue($queueName));

        self::assertSame(
            $number,
            $count,
            \sprintf('%d outbox events were created on queue "%s", but %d was expected', $count, $queueName, $number),
        );
    }

    #[Then('I got the event number :number from the outbox')]
    public function selectEventByNumber(int $number): void
    {
        $this->selectEvent($this->outbox->messages(), $number);
    }

    #[Then('I got the event number :number on queue :queueName from the outbox')]
    public function selectEventByNumberOnQueue(int $number, string $queueName): void
    {
        $this->selectEvent($this->outbox->messagesOnQueue($queueName), $number);
    }

    /**
     * @param class-string $fullyQualifiedClassName
     */
    #[Then('The outbox event should be of type :fullyQualifiedClassName')]
    public function outboxEventShouldBeOfType(string $fullyQualifiedClassName): void
    {
        self::assertInstanceOf($fullyQualifiedClassName, $this->selectedEvent());
    }

    #[Then('The outbox event aggregate id should be equal to :id')]
    public function outboxEventAggregateIdShouldBeEqualTo(string $id): void
    {
        $event = $this->selectedEvent();

        self::assertInstanceOf(DomainEvent::class, $event);
        self::assertSame($id, $event->aggregateId());
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
        foreach ($this->outbox->messages() as $message) {
            if ($this->eventMatchesTable($message['event'], $table)) {
                return;
            }
        }

        self::fail('No outbox event found containing the expected properties');
    }

    #[Then('there should not have been an outbox event created containing:')]
    public function noOutboxEventCreatedContaining(TableNode $table): void
    {
        foreach ($this->outbox->messages() as $message) {
            if ($this->eventMatchesTable($message['event'], $table)) {
                self::fail('An outbox event was found containing the properties that should be absent');
            }
        }
    }

    /**
     * Reconstructs the typed event from a stored-row-shaped JSON via the domain's own
     * {@see DomainEvent::fromPrimitives()} and dispatches it on the default bus inside a transaction, so
     * the persist-domain-event middleware writes the `event_store` row and the in-memory transport
     * receives the message. The JSON carries `aggregateId`, optional `eventId`/`occurredOn`, and a
     * `payload` object (the domain data, the same shape `toPrimitives()` returns).
     *
     * @throws JsonException
     */
    #[Then('I dispatch the :fullyQualifiedClassName outbox event with:')]
    public function dispatchOutboxEvent(string $fullyQualifiedClassName, PyStringNode $jsonPayload): void
    {
        if (!\is_a($fullyQualifiedClassName, DomainEvent::class, true)) {
            self::fail(\sprintf('"%s" is not a %s', $fullyQualifiedClassName, DomainEvent::class));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = (array) \json_decode($jsonPayload->getRaw(), true, 512, JSON_THROW_ON_ERROR);

        $aggregateId = \is_string($decoded['aggregateId'] ?? null) ? $decoded['aggregateId'] : '';
        $eventId = \is_string($decoded['eventId'] ?? null) ? $decoded['eventId'] : Uuid::generate();
        $occurredOn = \is_string($decoded['occurredOn'] ?? null)
            ? $decoded['occurredOn']
            : (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        $payload = [];

        if (\is_array($decoded['payload'] ?? null)) {
            foreach ($decoded['payload'] as $key => $value) {
                $payload[(string) $key] = $value;
            }
        }

        $event = $fullyQualifiedClassName::fromPrimitives($aggregateId, $payload, $eventId, $occurredOn);

        $this->entityManager->wrapInTransaction(function () use ($event): void {
            $this->messageBus->dispatch($event);
        });
    }

    #[Then('I remove event :number from the outbox')]
    public function removeEventByNumber(int $number): void
    {
        $messages = $this->outbox->messages();
        $message = $messages[$number - 1] ?? null;

        self::assertNotNull($message, \sprintf('No outbox event number %d found', $number));

        $this->outbox->remove($message);
    }

    /**
     * @param class-string $fullyQualifiedClassName
     */
    #[Then('I remove event of type :fullyQualifiedClassName from the outbox')]
    public function removeEventByType(string $fullyQualifiedClassName): void
    {
        foreach ($this->outbox->messages() as $message) {
            if ($message['event'] instanceof $fullyQualifiedClassName) {
                $this->outbox->remove($message);
            }
        }
    }

    #[Then('I print all outbox events')]
    public function printAllOutboxEvents(): void
    {
        foreach ($this->outbox->messages() as $index => $message) {
            echo \sprintf('#%d [%s] %s%s', $index + 1, $message['queue'], $this->describe($message['event']), PHP_EOL);
        }
    }

    #[Then('I print last outbox event')]
    public function printLastOutboxEvent(): void
    {
        $messages = $this->outbox->messages();
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
}
