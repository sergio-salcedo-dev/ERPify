<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\Json\Json;
use Erpify\Tests\Behat\Support\Messenger\Outbox;
use Erpify\Tests\Behat\Support\Messenger\OutboxEventFactory;
use Erpify\Tests\Behat\Support\PostProcess\JsonToolTrait;
use Erpify\Tests\Behat\Support\Table\PropertyTable;
use JsonException;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Gherkin steps over the event-driven *outbox*. Reading it — which logical queues are inspectable, and
 * how a queue resolves to a transport — belongs to {@see Outbox}; this class turns those reads into
 * assertions and owns the one piece of per-scenario state, the selected event.
 *
 * The permanent `event_store` (the append-only log, not the outbox) is asserted with raw-SQL steps;
 * nested payload fields are read here, on the pending event.
 *
 * Every step that reads the outbox names its queue. The unqualified phrasings are kept and refuse —
 * see {@see refuseUnqualified()} for why a position over the concatenation of every queue cannot be
 * asserted on.
 *
 * The suppressions below are measured, not inherited: 25 public methods and 32 methods are the 27
 * Gherkin patterns this context owns, and cutting them means splitting the step vocabulary across
 * contexts, which is a change to the feature files' surface rather than a refactor. Coupling sits one
 * over the threshold at 13, and class complexity one over at 51 — both are the arithmetic of holding
 * one vocabulary in one place, not a method anyone would want simpler.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
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
     *
     * The hook is what every scenario relies on. The step is a hand-invocation escape hatch for
     * draining mid-scenario, so no committed feature should call it: doing so would blank an outbox
     * the scenario had already filled, and the assertions after it would pass against nothing.
     */
    #[BeforeScenario]
    #[Given('I reset the outbox context')]
    public function reset(): void
    {
        $this->selectedEvent = null;

        $this->outbox->reset();
    }

    /**
     * Unqualified — refuses out loud. See {@see refuseUnqualified()}.
     */
    #[Then(':number outbox event was created')]
    #[Then(':number outbox events were created')]
    public function outboxEventsWereCreated(int $number): never
    {
        $this->refuseUnqualified(\sprintf('%d outbox events were created on the queue "async"', $number));
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

    /**
     * Unqualified — refuses out loud. See {@see refuseUnqualified()}.
     */
    #[Then('I got the event number :number from the outbox')]
    public function selectEventByNumber(int $number): never
    {
        $this->refuseUnqualified(\sprintf('I got the event number %d on queue "async" from the outbox', $number));
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

    /**
     * Unqualified — refuses out loud. See {@see refuseUnqualified()}.
     *
     * The table is bound by Behat from the step's signature, so the parameter has to stay for the
     * pattern to keep resolving at all; a refusal cannot read it.
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[Then('there should have been an outbox event created containing:')]
    public function anOutboxEventCreatedContaining(TableNode $table): never
    {
        $this->refuseUnqualified('there should have been an outbox event created on the queue "async" containing:');
    }

    #[Then('there should have been an outbox event created on the queue :queueName containing:')]
    public function anOutboxEventCreatedOnQueueContaining(string $queueName, TableNode $table): void
    {
        foreach ($this->outbox->messagesOnQueue($queueName) as $message) {
            if ($this->eventMatchesTable($message['event'], $table)) {
                return;
            }
        }

        self::fail('No outbox event found containing the expected properties');
    }

    /**
     * Unqualified — refuses out loud. See {@see refuseUnqualified()}.
     *
     * The table is bound by Behat from the step's signature, so the parameter has to stay for the
     * pattern to keep resolving at all; a refusal cannot read it.
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[Then('there should not have been an outbox event created containing:')]
    public function noOutboxEventCreatedContaining(TableNode $table): never
    {
        $this->refuseUnqualified(
            'there should not have been an outbox event created on the queue "async" containing:',
        );
    }

    #[Then('there should not have been an outbox event created on the queue :queueName containing:')]
    public function noOutboxEventCreatedOnQueueContaining(string $queueName, TableNode $table): void
    {
        foreach ($this->outbox->messagesOnQueue($queueName) as $message) {
            if ($this->eventMatchesTable($message['event'], $table)) {
                self::fail('An outbox event was found containing the properties that should be absent');
            }
        }
    }

    /**
     * Dispatches a reconstructed event on the default bus inside a transaction, so the
     * persist-domain-event middleware writes the `event_store` row and the in-memory transport
     * receives the message. The JSON carries `aggregateId`, optional `eventId`/`occurredOn`, and a
     * `payload` object — the same shape `toPrimitives()` returns. Rebuilding it is
     * {@see OutboxEventFactory}'s job.
     *
     * @param class-string $fullyQualifiedClassName
     *
     * @throws JsonException
     */
    #[Then('I dispatch the :fullyQualifiedClassName outbox event with:')]
    public function dispatchOutboxEvent(string $fullyQualifiedClassName, PyStringNode $jsonPayload): void
    {
        $event = OutboxEventFactory::fromJson($fullyQualifiedClassName, $jsonPayload->getRaw());

        $this->entityManager->wrapInTransaction(function () use ($event): void {
            $this->messageBus->dispatch($event);
        });
    }

    /**
     * Unqualified — refuses out loud. See {@see refuseUnqualified()}.
     */
    #[Then('I remove event :number from the outbox')]
    public function removeEventByNumber(int $number): never
    {
        $this->refuseUnqualified(\sprintf('I remove event %d on the queue "async" from the outbox', $number));
    }

    #[Then('I remove event :number on the queue :queueName from the outbox')]
    public function removeEventByNumberOnQueue(int $number, string $queueName): void
    {
        $messages = $this->outbox->messagesOnQueue($queueName);
        $message = $messages[$number - 1] ?? null;

        self::assertNotNull($message, \sprintf('No outbox event number %d found', $number));

        $this->outbox->remove($message);
    }

    /**
     * Unqualified — refuses out loud. See {@see refuseUnqualified()}.
     */
    #[Then('I remove event of type :fullyQualifiedClassName from the outbox')]
    public function removeEventByType(string $fullyQualifiedClassName): never
    {
        $this->refuseUnqualified(\sprintf(
            'I remove event of type "%s" on the queue "async" from the outbox',
            $fullyQualifiedClassName,
        ));
    }

    /**
     * @param class-string $fullyQualifiedClassName
     */
    #[Then('I remove event of type :fullyQualifiedClassName on the queue :queueName from the outbox')]
    public function removeEventByTypeOnQueue(string $fullyQualifiedClassName, string $queueName): void
    {
        foreach ($this->outbox->messagesOnQueue($queueName) as $message) {
            if ($message['event'] instanceof $fullyQualifiedClassName) {
                $this->outbox->remove($message);
            }
        }
    }

    /**
     * Hand-invocation escape hatch, like {@see printLastOutboxEvent()}: it asserts nothing and writes
     * to the formatter's output. Drop it into a feature while diagnosing one, then take it out —
     * committed, it prints on every CI run for a scenario that is no better pinned for having it.
     */
    #[Then('I print all outbox events')]
    public function printAllOutboxEvents(): void
    {
        foreach ($this->outbox->messages() as $index => $message) {
            echo \sprintf('#%d [%s] %s%s', $index + 1, $message['queue'], $this->describe($message['event']), PHP_EOL);
        }
    }

    /**
     * Hand-invocation escape hatch — see {@see printAllOutboxEvents()}.
     */
    #[Then('I print last outbox event')]
    public function printLastOutboxEvent(): void
    {
        $messages = $this->outbox->messages();
        $last = \end($messages);

        echo false === $last ? 'No outbox events' : $this->describe($last['event']);
    }

    /**
     * The unqualified phrasings stay registered and refuse, which is the point of keeping them: a
     * scenario that reaches for one gets the canonical form back instead of "step undefined", and
     * nobody re-adds the phrase believing it is missing.
     *
     * They cannot assert, because "the outbox" is not one queue: a read with no queue named
     * concatenates every inspectable queue into one 1-based list, so position 1 is an `async`
     * envelope right up until `async` happens to be empty and it silently becomes a `failed` one,
     * and a count sums queues the scenario never asked about. Nothing routes to `failed` under the
     * consume steps today — the private dispatcher carries no failure-transport listener — so the
     * ambiguity is latent by composition of the double, and a change there wakes it with nothing
     * going red.
     *
     * The queue-named siblings say the same things, and every committed scenario that counts, selects
     * or removes already uses them; the two table-match forms are idle on both sides of the rename,
     * which the registry records. This is not vocabulary being deleted — it is a near-duplicate
     * phrasing, whose halves would otherwise stay half-used, resolving to the one that can be read.
     */
    private function refuseUnqualified(string $qualified): never
    {
        throw new RuntimeException(\sprintf(
            'This step reads every inspectable outbox queue at once, so its position and its count '
            . 'both depend on how full a queue the scenario never named happens to be. Name the '
            . 'queue instead: %s',
            $qualified,
        ));
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

    /**
     * The exception is the predicate, and only a *failed assertion* may be read that way. Catching
     * everything would fold an unregistered modifier, an unparseable path and an unencodable event
     * into "did not match" — and in the negative step "did not match" is success, so a broken table
     * would prove the absence it was written to prove. What this cannot separate, because nothing
     * can: a misspelled property is genuinely absent, and absent is a legitimate non-match.
     */
    private function eventMatchesTable(object $event, TableNode $table): bool
    {
        try {
            $json = $this->eventToJson($event);

            foreach (PropertyTable::rows($table) as $property => $value) {
                $this->jsonPropertyShouldBeEqualTo($json, $property, $value);
            }
        } catch (AssertionFailedError) {
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
