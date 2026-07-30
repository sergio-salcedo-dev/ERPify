<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Tests\Support\PersistentTransportPolicy;
use Erpify\Tests\Unit\Shared\Architecture\Fixture\AsMessageRoutedFixtureEvent;
use Erpify\Tests\Unit\Shared\Architecture\Fixture\PersonAggregateFixtureEvent;
use Erpify\Tests\Unit\Shared\Architecture\Fixture\PersonScopedFixtureEvent;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over `api/.persistent-transport-policy`: the declared classification of every domain-event
 * aggregate type as person-denoting or not, cross-checked against Messenger's routing map. The resolution
 * rules live in {@see PersistentTransportPolicy}; this class is the assertions over them.
 *
 * A queued message outlives the erasure of whoever it is about. `async` and `failed` are Doctrine tables with
 * no TTL and no prune, and no erasure path touches them, so an id that reaches one of them survives the
 * deletion the application confirmed to the subject. This repo shipped exactly that: `PasswordResetCompleted`
 * was routed to `async` under a comment reasoning that its payload is the aggregate id alone — true, and
 * beside the point, because that aggregate is a user, so the id IS the personal datum.
 *
 * Two directions, both mechanical:
 *
 *   - **Completeness** — every `aggregateType()` declared in `src` must be classified, routed or not.
 *     Classifying only what is already routed would hand the decision to whoever routes it, in the same diff
 *     that introduces the defect; classifying up front means they have to overwrite an existing `person` line
 *     instead of authoring one.
 *   - **Policy** — a `person` aggregate reachable from a routing key or an `#[AsMessage]` attribute, on any
 *     transport other than `sync`, fails the build unless its line declares an ADR that exists.
 *
 * What it deliberately cannot do is judge the classification, and it says nothing about the payload or about
 * `event_store`; the registry header states those limits in full.
 *
 * @internal
 */
#[CoversNothing]
final class PersistentTransportPolicyGateTest extends TestCase
{
    /**
     * Failure preamble — a class const so make-target wrappers and CI log scrapers can grep the literal
     * string, and so the rule travels with the failure instead of just the offending event's name.
     */
    public const string FAILURE_PREAMBLE
        = 'An "aggregate id alone" payload is safe on a persisted transport if and only if the aggregate is '
        . 'not a natural person. `async` and `failed` have no TTL and no prune and no erasure path touches '
        . 'them, so a queued person aggregate id outlives the erasure the application confirmed to the '
        . 'subject. Unroute the event and handle it in-process, or declare the exception with an ADR in '
        . 'api/.persistent-transport-policy.';

    #[Test]
    public function everyAggregateTypeInSourceIsClassified(): void
    {
        $policy = $this->policy();
        $inUse = \array_values(\array_unique(\array_values($policy->eventsInSource())));
        \sort($inUse);

        $unclassified = \array_values(\array_diff($inUse, \array_keys($policy->classification())));

        $this->assertSame([], $unclassified, \sprintf(
            'These domain-event aggregate types are declared in src but not classified in '
            . '.persistent-transport-policy: %s. Declare each as `non-person` or `person` — an unclassified '
            . 'type lets whoever first routes it decide, in the very diff that queues the id.',
            \implode(', ', $unclassified),
        ));
    }

    #[Test]
    public function noPersonAggregateReachesAPersistedTransport(): void
    {
        $policy = $this->policy();
        $violations = $policy->violations($policy->configuredRoutes(), $policy->eventsInSource());

        if ([] === $violations) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail(self::FAILURE_PREAMBLE . "\n" . \implode("\n", $violations));
    }

    #[Test]
    public function everyDeclaredExceptionNamesAnAdrThatExists(): void
    {
        $policy = $this->policy();
        $exceptions = \array_filter(
            $policy->classification(),
            static fn (?string $adr): bool => null !== $adr && PersistentTransportPolicy::PERSON_NO_EXCEPTION !== $adr,
        );

        if ([] === $exceptions) {
            // No sanctioned exception today. Pin the assertion count so PHPUnit does not flag a risky test.
            $this->addToAssertionCount(1);

            return;
        }

        foreach ($exceptions as $aggregateType => $adr) {
            $this->assertFileExists(\dirname($policy->apiRoot()) . '/' . $adr, \sprintf(
                'The ADR declared for the queued person aggregate "%s" does not exist: %s. An exception '
                . 'without a recorded decision is just the defect with a comment on it.',
                $aggregateType,
                $adr,
            ));
        }
    }

    #[Test]
    public function theRegistryDeclaresNoAggregateTypeThatNothingEmits(): void
    {
        $policy = $this->policy();
        $stale = \array_values(\array_diff(
            \array_keys($policy->classification()),
            \array_values($policy->eventsInSource()),
        ));

        $this->assertSame([], $stale, \sprintf(
            'These aggregate types are classified but no event declares them any more: %s. Remove them so '
            . 'the registry stays a live inventory rather than a graveyard.',
            \implode(', ', $stale),
        ));
    }

    #[Test]
    public function theGateScansAtLeastOneEventAndOneRoutingEntry(): void
    {
        // A silent zero-scan on either side would make every check above vacuously green.
        $policy = $this->policy();

        $this->assertNotEmpty($policy->eventsInSource(), 'The gate discovered zero domain events.');
        $this->assertNotEmpty($policy->configuredRoutes(), 'The gate read zero routing entries.');
        $this->assertContains(
            PersistentTransportPolicy::PERSON_NO_EXCEPTION,
            $policy->classification(),
            'The registry classifies no aggregate as an unexcepted person, so the policy check cannot fire.',
        );
    }

    /**
     * @param array<string, list<string>> $routes
     */
    #[DataProvider('provideTheGateCatchesEveryRoutingShapeMessengerResolvesCases')]
    #[Test]
    public function theGateCatchesEveryRoutingShapeMessengerResolves(array $routes): void
    {
        $this->assertNotSame(
            [],
            $this->policy()->violations($routes, $this->fixtureEvents()),
            'The gate missed a routing shape Messenger resolves, so the leak can return with the build green.',
        );
    }

    /**
     * The routing shapes `SendersLocator` resolves. All but the first are not a concrete event class, so a
     * gate reading routing keys as class names would step straight over them — and its completeness check
     * could not classify them either, leaving the build green while the leak returned.
     *
     * @return iterable<string, array{array<string, list<string>>}>
     */
    public static function provideTheGateCatchesEveryRoutingShapeMessengerResolvesCases(): iterable
    {
        yield 'exact class' => [[PersonAggregateFixtureEvent::class => ['async']]];
        yield 'parent class' => [[DomainEvent::class => ['async']]];
        yield 'implemented interface' => [[PersonScopedFixtureEvent::class => ['async']]];
        yield 'namespace wildcard' => [['Erpify\Tests\Unit\Shared\Architecture\Fixture\*' => ['async']]];
        yield 'bare catch-all' => [['*' => ['async']]];
    }

    #[Test]
    public function theGateReadsTheAsMessageAttributeThatNeedsNoRoutingEntry(): void
    {
        // The one shape invisible to the config: with routing empty, the attribute alone still puts the
        // message on `async`. Asserted on the extraction, since folding it into an entry keyed by the class
        // is what makes the policy check see it at all.
        $policy = $this->policy();
        $events = [AsMessageRoutedFixtureEvent::class => AsMessageRoutedFixtureEvent::aggregateType()];
        $routes = $policy->attributeRoutes($events);

        $this->assertSame([AsMessageRoutedFixtureEvent::class => ['async']], $routes);
        $this->assertNotSame([], $policy->violations($routes, $events));
    }

    #[Test]
    public function theGateAcceptsTheSanctionedNonPersistedTransport(): void
    {
        $this->assertSame(
            [],
            $this->policy()->violations(
                ['*' => [PersistentTransportPolicy::NON_PERSISTED_TRANSPORT]],
                $this->fixtureEvents(),
            ),
            'The gate flagged the one transport that never persists a body — false positive.',
        );
    }

    #[Test]
    public function theGateIgnoresNonPersonAggregates(): void
    {
        // Otherwise "everything fails" would masquerade as "the rule is enforced".
        $this->assertSame(
            [],
            $this->policy()->violations(['*' => ['async']], ['Erpify\Fixture\Absent' => 'Backoffice.Bank']),
        );
    }

    /**
     * @return array<string, string>
     */
    private function fixtureEvents(): array
    {
        return [PersonAggregateFixtureEvent::class => PersonAggregateFixtureEvent::AGGREGATE_TYPE];
    }

    private function policy(): PersistentTransportPolicy
    {
        return PersistentTransportPolicy::fromGateLocation(__DIR__);
    }
}
