<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Step\Then;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Infrastructure\Persistence\Entity\StoredDomainEvent;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\Json\Json;
use Erpify\Tests\Behat\Support\PostProcess\JsonToolTrait;
use JsonException;

/**
 * Asserts the persisted side of the domain-event seam: the `domain_event` store rows that
 * {@see \Erpify\Shared\Infrastructure\Messenger\PersistDomainEventMiddleware} writes inside the
 * same transaction as the aggregate (see ADR `event-driven-architecture.md`). The middleware runs
 * on the bus regardless of transport binding, so these rows exist in the in-memory test setup too.
 *
 * Complements {@see MessengerContext} (which inspects the transport envelope): here the assertion
 * is over the stored, JSON-decoded `toPrimitives()` payload, so a scenario can pin a nested field
 * such as `bankId` on the recorded event, not just its existence.
 */
final class OutboxContext extends AbstractContext
{
    use JsonToolTrait;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Then(':count domain event named :name should be stored')]
    #[Then(':count domain events named :name should be stored')]
    public function domainEventsNamedShouldBeStored(int $count, string $name): void
    {
        $found = \count($this->storedEventsNamed($name));

        self::assertSame(
            $count,
            $found,
            \sprintf('%d "%s" domain events are stored, but %d was expected', $found, $name, $count),
        );
    }

    #[Then('the stored domain event :name should have aggregate id :aggregateId')]
    public function theStoredDomainEventShouldHaveAggregateId(string $name, string $aggregateId): void
    {
        self::assertSame($aggregateId, $this->latestStoredEventNamed($name)->aggregateId());
    }

    /**
     * @throws JsonException
     */
    #[Then('the stored domain event :name body :property should be equal to :value')]
    public function theStoredDomainEventBodyShouldBeEqualTo(string $name, string $property, string $value): void
    {
        $body = $this->latestStoredEventNamed($name)->body();

        $this->jsonPropertyShouldBeEqualTo(new Json(\json_encode($body, JSON_THROW_ON_ERROR)), $property, $value);
    }

    private function latestStoredEventNamed(string $name): StoredDomainEvent
    {
        $events = $this->storedEventsNamed($name);
        // Most recent wins when a scenario produced several events of the same name; payload
        // assertions then read the event the step under test just appended.
        $latest = \end($events);

        self::assertInstanceOf(
            StoredDomainEvent::class,
            $latest,
            \sprintf('No "%s" domain event is stored', $name),
        );

        return $latest;
    }

    /**
     * @return list<StoredDomainEvent>
     */
    private function storedEventsNamed(string $name): array
    {
        return $this->entityManager
            ->getRepository(StoredDomainEvent::class)
            ->findBy(['name' => $name], ['occurredOn' => 'ASC'])
        ;
    }
}
