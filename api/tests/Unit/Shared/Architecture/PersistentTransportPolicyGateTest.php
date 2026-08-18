<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\MessengerRoutingConfig;
use Erpify\Tests\Support\PersistentTransportPolicy;
use Erpify\Tests\Unit\Shared\Architecture\Fixture\PersonAggregateFixtureEvent;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over `api/.persistent-transport-policy`: the declared classification of every domain-event
 * aggregate type as person-denoting or not, cross-checked against Messenger's routing map. The resolution
 * rules live in {@see PersistentTransportPolicy}; this class is the assertions over them.
 *
 * A queued message outlives the erasure of whoever it is about. No erasure path touches `async` or `failed`,
 * and neither clears one in time — the first is unbounded, the second swept only at 30 days — so an id that
 * reaches either survives the deletion the application confirmed to the subject. This repo shipped exactly
 * that: `PasswordResetCompleted`
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
        . 'not a natural person. No erasure path touches `async` or `failed`, and their retention does not '
        . 'stand in for one — `async` is unbounded and `failed` is swept at 30 days — so a queued person '
        . 'aggregate id outlives the erasure the application confirmed to the subject. Unroute the event '
        . 'and handle it in-process, or declare the exception with an ADR in '
        . 'api/.persistent-transport-policy.';

    #[Test]
    public function theGateAcceptsTheSanctionedNonPersistedTransport(): void
    {
        $this->assertSame(
            [],
            $this->policy()->violations(
                ['*' => [MessengerRoutingConfig::NON_PERSISTED_TRANSPORT]],
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
        $violations = $policy->violations($policy->config()->configuredRoutes(), $policy->eventsInSource());

        $this->assertSame([], $violations, self::FAILURE_PREAMBLE . "\n" . \implode("\n", $violations));
    }

    #[Test]
    public function everyDeclaredExceptionNamesAnAdrThatExists(): void
    {
        $policy = $this->policy();
        $exceptions = \array_filter(
            $policy->classification(),
            static fn (?string $adr): bool => null !== $adr && PersistentTransportPolicy::PERSON_NO_EXCEPTION !== $adr,
        );

        $defects = [];

        foreach ($exceptions as $aggregateType => $adr) {
            // assertFileExists() is file_exists(), which is true for a DIRECTORY — so `person :: docs`
            // would silence the policy check with no ADR written at all.
            $absolute = \dirname($policy->apiRoot()) . '/' . $adr;

            if (\is_file($absolute) && \str_ends_with($adr, '.md')) {
                continue;
            }

            $defects[] = \sprintf(
                'The ADR declared for the queued person aggregate "%s" is not an existing Markdown file: %s. '
                . 'An exception without a recorded decision is just the defect with a comment on it.',
                $aggregateType,
                $adr,
            );
        }

        // The sanctioned-exception set is empty in the normal state, so this asserts over nothing
        // most days. That is the check having no subject, not the check being satisfied: what keeps
        // the registry itself from emptying out is theGateScansAtLeastOneEventAndOneRoutingEntry.
        $this->assertSame([], $defects, \implode("\n", $defects));
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
        $this->assertNotEmpty($policy->config()->configuredRoutes(), 'The gate read zero routing entries.');
        // Guards vacuity WITHOUT deadlocking against theRegistryDeclaresNoAggregateTypeThatNothingEmits:
        // asserting specifically on an UNEXCEPTED person would make the two mutually unsatisfiable the day
        // the only person type earns an ADR exception — one test would demand the line go, the other stay.
        $persons = \array_filter($policy->classification(), static fn (?string $adr): bool => null !== $adr);

        $this->assertNotEmpty(
            $persons,
            'The registry classifies no aggregate as a person, so the policy check can never fire.',
        );
    }

    private function policy(): PersistentTransportPolicy
    {
        return PersistentTransportPolicy::fromGateLocation(__DIR__);
    }
}
