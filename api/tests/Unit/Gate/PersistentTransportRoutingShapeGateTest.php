<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Tests\Support\PersistentTransportPolicy;
use Erpify\Tests\Unit\Gate\Fixture\AsMessageInheritedFixtureEvent;
use Erpify\Tests\Unit\Gate\Fixture\AsMessageRoutedFixtureEvent;
use Erpify\Tests\Unit\Gate\Fixture\PersonAggregateFixtureEvent;
use Erpify\Tests\Unit\Gate\Fixture\PersonScopedFixtureEvent;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Half of the persistent-transport gate: that it sees every way Messenger can put a message on a transport.
 * The registry and the policy over it are {@see PersistentTransportPolicyGateTest}.
 *
 * This half exists because the obvious implementation is wrong in a way that reads as right. `SendersLocator`
 * resolves senders through `HandlersLocator::listTypes()` — the class, its parents, its interfaces, namespace
 * wildcards and a bare `'*'` — then falls back to `#[AsMessage(transport:)]`, matched with
 * `ReflectionAttribute::IS_INSTANCEOF` across the class, its parents and its interfaces, and merged across
 * repetitions. None of those is a concrete event class a gate could reflect on directly, and PHP does not
 * inherit attributes, so a reader that takes the simple path is blind to all of them while looking complete.
 * Each shape gets its own fixture: a mechanism certified by a test covering only its easy case is worse than
 * a documented gap, because the green tick stops anyone looking.
 *
 * @internal
 */
#[CoversNothing]
final class PersistentTransportRoutingShapeGateTest extends TestCase
{
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
        yield 'namespace wildcard' => [['Erpify\Tests\Unit\Gate\Fixture\*' => ['async']]];
        yield 'bare catch-all' => [['*' => ['async']]];
    }

    #[Test]
    public function theGateReadsTheAsMessageAttributeThatNeedsNoRoutingEntry(): void
    {
        // The shape invisible to the config: with routing empty, the attribute alone puts the message on
        // `async`. It is declared twice here because `AsMessage` is repeatable and Symfony MERGES every
        // occurrence — a reader that assigns keeps only the last, so with the persisted transport declared
        // first it would report the harmless `sync` and stay green.
        $policy = $this->policy();
        $transports = $policy->attributeTransportsFor(AsMessageRoutedFixtureEvent::class);

        \sort($transports);
        $this->assertSame(['async', 'sync'], $transports);
        $this->assertNotSame([], $policy->violations([], [
            AsMessageRoutedFixtureEvent::class => AsMessageRoutedFixtureEvent::aggregateType(),
        ]));
    }

    #[Test]
    public function theGateReadsAnAsMessageAttributeInheritedFromAParentOrAnInterface(): void
    {
        // PHP does not inherit attributes, so reflecting the concrete class alone — the obvious
        // implementation — returns nothing here. Symfony walks class_parents() and class_implements() and
        // matches subclasses of the attribute with ReflectionAttribute::IS_INSTANCEOF, so putting
        // #[AsMessage] on an abstract base is a supported way to route a whole aggregate at once.
        $policy = $this->policy();
        $transports = $policy->attributeTransportsFor(AsMessageInheritedFixtureEvent::class);

        \sort($transports);
        $this->assertSame(
            ['async', 'failed'],
            $transports,
            'The gate misses a routing attribute declared on a parent, an interface, or as an AsMessage subclass.',
        );
        $this->assertNotSame([], $policy->violations([], [
            AsMessageInheritedFixtureEvent::class => AsMessageInheritedFixtureEvent::aggregateType(),
        ]));
    }

    #[Test]
    public function theGateReadsTheDeprecatedNestedSendersRoutingShape(): void
    {
        // `{senders: [async]}` is deprecated in Symfony 8.1 and removed in 9.0, but Configuration.php still
        // normalises it, so it routes today. A reader that keeps only string members drops it silently and
        // leaves the pre-8.1 form — the one an older doc or an older project hands you — as a clean bypass.
        $routes = $this->policy()->config()->configuredRoutes();

        $this->assertNotSame([], $routes, 'Precondition: the gate read no routing at all.');
        $this->assertNotSame([], $this->policy()->violations(
            [PersonAggregateFixtureEvent::class => ['async']],
            $this->fixtureEvents(),
        ));
    }

    #[Test]
    public function aWildcardIsAFallbackAndDoesNotOverrideAConcreteRoute(): void
    {
        // `SendersLocator` skips a wildcard type once a concrete type has already matched. A gate that read
        // routing keys independently would report `{'*': async, Event: sync}` as a violation even though
        // Symfony sends that event to `sync` alone — a red build on correct config, which is precisely how a
        // gate teaches people to route around it.
        $this->assertSame(
            [],
            $this->policy()->violations(
                ['*' => ['async'], PersonAggregateFixtureEvent::class => ['sync']],
                $this->fixtureEvents(),
            ),
            'The gate treats a wildcard as unconditional; Symfony treats it as a fallback.',
        );
    }

    #[Test]
    public function everyEnvironmentsRoutingCounts(): void
    {
        // Sections are read in document order, so a `when@<env>` entry for a key already routed in
        // production must UNION with it, never replace it. Replacing would make the gate weaker in exactly
        // the direction that matters, since the test environment maps every transport to in-memory.
        $policy = $this->policy();

        $this->assertNotSame([], $policy->violations(
            [PersonAggregateFixtureEvent::class => ['async', 'sync']],
            $this->fixtureEvents(),
        ), 'A person aggregate routed to a persisted transport in ANY environment must fail the gate.');
    }

    #[Test]
    public function theSanctionedTransportStillHasANonPersistingDsn(): void
    {
        // Everything else here trusts the NAME `sync`. Redefining it to a Doctrine DSN would turn the one
        // sanctioned escape hatch into the leak, with every check still green.
        $this->assertSame([], $this->policy()->config()->misdeclaredNonPersistedTransports());
    }

    #[Test]
    public function theDurableTransportStillHasATransactionalDsn(): void
    {
        // The dual of the `sync` assertion above. `async` is trusted by NAME as durable and on the caller's
        // own connection: that is where the after-commit guarantee comes from, not from the routing entry.
        // It also pins the test environment's in-memory substitution, which does NOT join the transaction —
        // so a change to it goes red and the "no test here can demonstrate this" claim has to be revisited
        // rather than silently stop being true.
        $this->assertSame([], $this->policy()->config()->misdeclaredPersistedTransport());
    }

    #[Test]
    public function noPhpConfigFileDeclaresMessengerConfiguration(): void
    {
        // PHP config is executed, not parsed. Rather than a partial reader that would quietly under-report,
        // the gate refuses to let Messenger config exist in a form it cannot read.
        $this->assertSame(
            [],
            $this->policy()->config()->phpConfigFilesTouchingMessenger(),
            "Messenger configuration in a PHP config file is outside this gate's reach. Declare it in YAML, "
            . 'or extend the gate to evaluate PHP config before adding it.',
        );
    }

    #[Test]
    public function theGateReadsEveryConfigFileSymfonyWouldLoad(): void
    {
        // Symfony merges config/packages/*.yaml, config/packages/<env>/*.yaml and config/services*.yaml into
        // one extension config. Reading only packages/messenger.yaml would leave an env-specific override
        // file routing for real, invisibly — and config/packages/test/ already exists as the precedent.
        $files = $this->policy()->config()->configFiles();

        $this->assertGreaterThan(1, \count($files), 'The gate reads a single config file.');
        $this->assertNotEmpty(\array_filter(
            $files,
            static fn (string $file): bool => \str_ends_with($file, '/packages/messenger.yaml'),
        ));
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
