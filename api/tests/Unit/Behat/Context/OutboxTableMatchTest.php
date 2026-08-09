<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Context;

use Behat\Gherkin\Node\TableNode;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Tests\Behat\Context\OutboxContext;
use Erpify\Tests\Behat\NodeModifier\NodeModifierLocator;
use Erpify\Tests\Behat\NodeModifier\Scalar\NullNodeModifier;
use Erpify\Tests\Behat\NodeModifier\Scalar\StringNodeModifier;
use Erpify\Tests\Behat\Support\Messenger\MessengerTransports;
use Erpify\Tests\Behat\Support\Messenger\Outbox;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Pins the one property of the outbox table steps that no scenario above them can observe: an event
 * that does not carry the asked-for property must not match.
 *
 * The steps decide "does this event match" by running an equality assertion and catching whatever it
 * throws — the exception *is* the predicate. That makes them uniquely fragile to any change in how a
 * missing dot-path is reported: the moment an absent path stops throwing and starts reading as `null`,
 * `there should have been an outbox event created … containing:` matches an event that never had the
 * property, and a step written to pin a payload field starts passing against its absence. Nothing goes
 * red when that happens, which is why the guard has to live here rather than in a feature: Gherkin can
 * assert that a step passes, never that it fails.
 *
 * The matching cases are not decoration. Without them a step that refused everything would satisfy the
 * absent-property tests just as well, and the file would prove nothing.
 *
 * Two modifiers, not the container's full set: neither claims a value by auto-detection, so an expected
 * value reaches the comparison exactly as the table wrote it and the subject under test stays the
 * predicate rather than modifier resolution.
 *
 * {@see CoversNothing} because the subject is test infrastructure — `tests/` sits outside the coverage
 * allowlist, so there is no production line here to credit.
 *
 * @internal
 */
#[CoversNothing]
final class OutboxTableMatchTest extends TestCase
{
    private const string ASYNC = 'async';

    private const string ASYNC_SERVICE = 'messenger.transport.async';

    private const string FAILED_SERVICE = 'messenger.transport.failed';

    public function testATableOfPropertiesTheEventCarriesFindsIt(): void
    {
        $context = $this->contextHolding($this->event(['bankId' => 'ACME', 'name' => 'Acme Bank']));

        // The equality the step runs to decide the match is the assertion this test counts.
        $context->anOutboxEventCreatedOnQueueContaining(self::ASYNC, new TableNode([['bankId', 'ACME']]));
    }

    public function testAPropertyTheEventDoesNotCarryDoesNotMatch(): void
    {
        $context = $this->contextHolding($this->event(['bankId' => 'ACME']));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No outbox event found containing the expected properties');

        $context->anOutboxEventCreatedOnQueueContaining(self::ASYNC, new TableNode([['absentProperty', 'ACME']]));
    }

    public function testAPropertyTheEventCarriesWithAnotherValueDoesNotMatch(): void
    {
        $context = $this->contextHolding($this->event(['bankId' => 'ACME']));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No outbox event found containing the expected properties');

        $context->anOutboxEventCreatedOnQueueContaining(self::ASYNC, new TableNode([['bankId', 'OTHER']]));
    }

    public function testTheNegativeFormRefusesAnEventThatDoesCarryTheProperties(): void
    {
        $context = $this->contextHolding($this->event(['bankId' => 'ACME']));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('An outbox event was found containing the properties that should be absent');

        $context->noOutboxEventCreatedOnQueueContaining(self::ASYNC, new TableNode([['bankId', 'ACME']]));
    }

    public function testTheNegativeFormAcceptsAPropertyNoEventCarries(): void
    {
        $context = $this->contextHolding($this->event(['bankId' => 'ACME']));

        // The equality the step runs to decide the match is the assertion this test counts.
        $context->noOutboxEventCreatedOnQueueContaining(self::ASYNC, new TableNode([['absentProperty', 'ACME']]));
    }

    /**
     * @return iterable<string, array{Closure(OutboxContext): void}>
     */
    public static function unqualifiedSteps(): iterable
    {
        yield 'count' => [static function (OutboxContext $c): void { $c->outboxEventsWereCreated(1); }];
        yield 'select by number' => [static function (OutboxContext $c): void { $c->selectEventByNumber(1); }];
        yield 'remove by number' => [static function (OutboxContext $c): void { $c->removeEventByNumber(1); }];
        yield 'remove by type' => [
            static function (OutboxContext $c): void { $c->removeEventByType(stdClass::class); },
        ];
        yield 'table match' => [
            static function (OutboxContext $c): void {
                $c->anOutboxEventCreatedContaining(new TableNode([['bankId', 'ACME']]));
            },
        ];
        yield 'negative table match' => [
            static function (OutboxContext $c): void {
                $c->noOutboxEventCreatedContaining(new TableNode([['bankId', 'ACME']]));
            },
        ];
    }

    /**
     * Each unqualified phrasing stays registered so a scenario reaching for it is told the canonical
     * form rather than that the step does not exist — and every one of them refuses, because a
     * position or a count over the concatenation of every queue answers a question the scenario did
     * not ask. A refusal that returned quietly would be the worst of both.
     */
    #[DataProvider('unqualifiedSteps')]
    public function testAnUnqualifiedStepRefusesAndNamesTheQueuedForm(Closure $step): void
    {
        $context = $this->contextHolding($this->event(['bankId' => 'ACME']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Name the queue instead:');

        $step($context);
    }

    /**
     * @param array<string, string> $properties
     */
    private function event(array $properties): stdClass
    {
        $event = new stdClass();

        foreach ($properties as $name => $value) {
            $event->{$name} = $value;
        }

        return $event;
    }

    private function contextHolding(object ...$events): OutboxContext
    {
        $async = new InMemoryTransport();

        foreach ($events as $event) {
            $async->send(new Envelope($event));
        }

        $container = new Container();
        $container->set(self::ASYNC_SERVICE, $async);
        $container->set(self::FAILED_SERVICE, new InMemoryTransport());

        $context = new OutboxContext(
            new Outbox(new MessengerTransports($container)),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(EntityManagerInterface::class),
        );
        $context->setNodeModifierLocator(new NodeModifierLocator([new NullNodeModifier(), new StringNodeModifier()]));

        return $context;
    }
}
