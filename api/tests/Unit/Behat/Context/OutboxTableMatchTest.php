<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Context;

use Behat\Gherkin\Node\TableNode;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Tests\Behat\Context\OutboxContext;
use Erpify\Tests\Unit\Behat\Context\Fixtures\OutboxContextFactory;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;

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

    public function testATableOfPropertiesTheEventCarriesFindsIt(): void
    {
        $context = $this->contextHolding((object) ['bankId' => 'ACME', 'name' => 'Acme Bank']);

        // The equality the step runs to decide the match is the assertion this test counts.
        $context->anOutboxEventCreatedOnQueueContaining(self::ASYNC, new TableNode([['bankId', 'ACME']]));
    }

    public function testAPropertyTheEventDoesNotCarryDoesNotMatch(): void
    {
        $context = $this->contextHolding((object) ['bankId' => 'ACME']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No outbox event found containing the expected properties');

        $context->anOutboxEventCreatedOnQueueContaining(self::ASYNC, new TableNode([['absentProperty', 'ACME']]));
    }

    public function testAPropertyTheEventCarriesWithAnotherValueDoesNotMatch(): void
    {
        $context = $this->contextHolding((object) ['bankId' => 'ACME']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No outbox event found containing the expected properties');

        $context->anOutboxEventCreatedOnQueueContaining(self::ASYNC, new TableNode([['bankId', 'OTHER']]));
    }

    public function testTheNegativeFormRefusesAnEventThatDoesCarryTheProperties(): void
    {
        $context = $this->contextHolding((object) ['bankId' => 'ACME']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('An outbox event was found containing the properties that should be absent');

        $context->noOutboxEventCreatedOnQueueContaining(self::ASYNC, new TableNode([['bankId', 'ACME']]));
    }

    public function testTheNegativeFormAcceptsAPropertyNoEventCarries(): void
    {
        $context = $this->contextHolding((object) ['bankId' => 'ACME']);

        // The equality the step runs to decide the match is the assertion this test counts.
        $context->noOutboxEventCreatedOnQueueContaining(self::ASYNC, new TableNode([['absentProperty', 'ACME']]));
    }

    private function contextHolding(object ...$events): OutboxContext
    {
        return OutboxContextFactory::holding(
            $this->createStub(MessageBusInterface::class),
            $this->createStub(EntityManagerInterface::class),
            ...$events,
        );
    }
}
