<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Erpify\Tests\Behat\Context\MessengerConsumerContext;
use Erpify\Tests\Behat\Support\Execution\LastRun;
use Erpify\Tests\Behat\Support\Messenger\MessengerTransports;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The two ways a consume step used to report success over a run that asserted nothing.
 *
 * All three are invisible from Gherkin, which is why they have to be pinned here: a feature can assert
 * that a step passes, never that it fails, and each defect's symptom IS the step passing. A worker
 * holding no receiver, and a worker that read fewer messages than the step named, both end on the
 * time-limit listener with exit code 0 and an output nobody can tell from a real consume.
 *
 * The third defect of that family — verbosity resolved by last flag rather than by maximum — is pinned
 * in {@see MessengerVerbosityResolutionTest}, which needs none of this class's wiring.
 *
 * The matching halves are not decoration. Without them a step that refused every consume would satisfy
 * the refusing halves just as well, and the file would prove nothing.
 *
 * {@see IgnoreDeprecations} because {@see \Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener}
 * is deprecated as of Symfony 8.1 and the context under test still carries it; that is a property of the
 * subject, not of these tests, and `failOnDeprecation` would otherwise report it once, on whichever test
 * happened to autoload the file first.
 *
 * {@see CoversNothing} because the subject is test infrastructure — `tests/` sits outside the coverage
 * allowlist, so there is no production line here to credit.
 *
 * @internal
 */
#[CoversNothing]
#[IgnoreDeprecations]
final class MessengerConsumerContextTest extends TestCase
{
    private const string ASYNC = 'async';

    /**
     * Long enough to be a real run and short enough that a defect's spin costs a second, not five.
     */
    private const int TIME_LIMIT = 1;

    private LastRun $lastRun;

    /**
     * `contextHolding()` mints a fresh recorder per context, so the guard that refuses a second
     * unread run never fires across the tests here. Initialising it up front is what lets a reader
     * — and PHPStan — see the property is never observed before a context exists.
     */
    protected function setUp(): void
    {
        $this->lastRun = new LastRun();
    }

    /**
     * A `receivers` key that resolves to nothing leaves the worker with an empty receiver map: it reads
     * no transport, idles until the time limit and records exit code 0 — success over a consume that
     * never happened.
     */
    public function testAConsumeResolvingNoReceiverIsRefusedRatherThanRunToItsTimeLimit(): void
    {
        $context = $this->contextHolding(0);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('No transport name resolved to a receiver');

        $context->iExecuteCommandWithOptions('messenger:consume', $this->options([
            'receivers' => [],
            '--time-limit' => self::TIME_LIMIT,
        ]));
    }

    /**
     * The refusal names what it could not use, so a malformed `receivers` entry is diagnosable from the
     * message alone rather than from re-reading the JSON.
     */
    public function testTheRefusalNamesTheTransportNamesItCouldNotUse(): void
    {
        $context = $this->contextHolding(0);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('unusable: int');

        $context->iExecuteCommandWithOptions('messenger:consume', $this->options([
            'receivers' => [42],
            '--time-limit' => self::TIME_LIMIT,
        ]));
    }

    /**
     * The matching half of both refusals above: a resolvable receiver runs, and the raw-CLI step does
     * NOT hold the run to its `--limit`. That path models `messenger:consume` parity, where the limit is
     * a ceiling and stopping on the time limit with the queue short is a legitimate outcome — so the
     * count assertion belongs to the `I consume N …` phrasings and to those only.
     */
    public function testTheRawCommandStepAcceptsARunThatStopsShortOfItsLimit(): void
    {
        $context = $this->contextHolding(1);

        $context->iExecuteCommandWithOptions('messenger:consume', $this->options([
            'receivers' => [self::ASYNC],
            '--limit' => 2,
            '--time-limit' => self::TIME_LIMIT,
        ]));

        $this->assertSame(0, $this->lastRun->exitCode());
    }

    /**
     * Fewer messages pending than the step named is not a shorter run: the time-limit listener stops the
     * worker, `run()` returns, and nothing counts what was handled.
     */
    public function testAConsumeThatReadsFewerMessagesThanTheStepNamedIsRefused(): void
    {
        $context = $this->contextHolding(1);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('Asked for 2 message(s) from transport "async"');

        $context->iConsumeWithTimeLimit(2, self::ASYNC, self::TIME_LIMIT);
    }

    /**
     * The matching half. See {@see testAConsumeThatReadsFewerMessagesThanTheStepNamedIsRefused()}.
     */
    public function testAConsumeThatReadsExactlyTheCountTheStepNamedPasses(): void
    {
        $context = $this->contextHolding(2);

        $context->iConsume(2, self::ASYNC);

        $this->assertSame(0, $this->lastRun->exitCode());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function options(array $options): PyStringNode
    {
        return new PyStringNode([\json_encode($options, JSON_THROW_ON_ERROR)], 0);
    }

    private function contextHolding(int $pending): MessengerConsumerContext
    {
        $transport = new InMemoryTransport();

        for ($i = 0; $i < $pending; ++$i) {
            $transport->send(new Envelope(new stdClass()));
        }

        $container = new Container();
        $container->set('messenger.transport.' . self::ASYNC, $transport);

        $this->lastRun = new LastRun();

        // A bus with no middleware acknowledges every envelope, so a message the worker reads is a
        // message the worker counts — which is the whole of what these tests need from it.
        return new MessengerConsumerContext(
            $this->lastRun,
            new MessengerTransports($container),
            new MessageBus(),
            $this->createStub(HandlersLocatorInterface::class),
        );
    }
}
