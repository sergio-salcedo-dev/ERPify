<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Step\Then;
use Behat\Step\When;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\Execution\LastRun;
use Erpify\Tests\Behat\Support\Messenger\MessengerTransports;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;
use Throwable;

/**
 * Drives a real Messenger {@see Worker} so a scenario can assert the effects only the full consuming
 * pipeline produces — the receiving-side bus middleware (idempotency claim in `handled_domain_event`)
 * and the handlers themselves (notification email, realtime publish). For a lightweight handler-only
 * invocation use "message N ... is processed".
 *
 * The worker is constructed against the very transport instance the rest of the suite reads, then run
 * with a message-limit and a time-limit backstop on a private dispatcher. This deliberately bypasses
 * `messenger:consume` via a booted console application: that path resets `ResetInterface` services —
 * the `in-memory://` transport among them — wiping the queued message before the worker reads it, so
 * no handler would run. Running the `Worker` class directly keeps the queued message and is still the
 * real consume (same worker, bus middleware and handlers), only without the CLI wrapper.
 *
 * What the private dispatcher costs, since it is invisible until someone writes a scenario against it:
 * none of the framework's `WorkerMessageFailedEvent` listeners are subscribed to it — not the retry
 * strategy, not `SendFailedMessageToFailureTransportListener`, not Sentry. A handler that throws under
 * these steps is therefore never retried and never routed to the `failed` transport, so no scenario
 * here can assert failure routing; a count on `failed` would read 0 by construction rather than by
 * behaviour. Covering that needs the listeners wired in deliberately, not an assertion.
 *
 * The worker's own logger is a {@see ConsoleLogger} over a buffer, and both go into {@see LastRun}, so
 * `the last run should succeed` and `the last run output should contain` can assert it ran (e.g. the
 * "handled successfully" acknowledgement) in the same words a console scenario uses.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final class MessengerConsumerContext extends AbstractContext
{
    /**
     * Backstop wall-clock budget (seconds) for a single consume, so an over-stated message limit
     * degrades to "stop after a few seconds" instead of blocking the suite forever.
     */
    private const int TIME_LIMIT_SECONDS = 5;

    /**
     * The levels `Application::configureIO()` resolves these to, and the long form is the one worth
     * stating: bare `--verbose` is level ONE there, the same as `-v` — level two needs `-vv` or an
     * explicit `--verbose=2`. Mapping it to VERY_VERBOSE would make this context more generous than the
     * CLI it claims parity with, so a step asserting on output the real command emits only at `-vv`
     * would pass here and fail there.
     *
     * @var array<string, int>
     */
    private const array VERBOSITY_FLAGS = [
        '-v' => OutputInterface::VERBOSITY_VERBOSE,
        '-vv' => OutputInterface::VERBOSITY_VERY_VERBOSE,
        '-vvv' => OutputInterface::VERBOSITY_DEBUG,
        '--verbose' => OutputInterface::VERBOSITY_VERBOSE,
    ];

    public function __construct(
        private readonly LastRun $lastRun,
        private readonly MessengerTransports $transports,
        #[Autowire(service: 'messenger.routable_message_bus')]
        private readonly MessageBusInterface $routableMessageBus,
        #[Autowire(service: 'messenger.bus.default.messenger.handlers_locator')]
        private readonly HandlersLocatorInterface $handlersLocator,
    ) {
    }

    #[When('I consume :count message from the :transportName transport')]
    #[When('I consume :count messages from the :transportName transport')]
    public function iConsume(int $count, string $transportName): void
    {
        $this->consumeExactly($count, $transportName, self::TIME_LIMIT_SECONDS);
    }

    #[When('I consume :count message from the :transportName transport with time limit :seconds')]
    #[When('I consume :count messages from the :transportName transport with time limit :seconds')]
    public function iConsumeWithTimeLimit(int $count, string $transportName, int $seconds): void
    {
        $this->consumeExactly($count, $transportName, $seconds);
    }

    /**
     * @throws JsonException
     */
    #[When('I execute :command with options:')]
    public function iExecuteCommandWithOptions(string $command, PyStringNode $options): void
    {
        self::assertSame(
            'messenger:consume',
            $command,
            \sprintf('Only "messenger:consume" is supported, got "%s"', $command),
        );

        /** @var array<string, mixed> $decoded */
        $decoded = (array) \json_decode($options->getRaw(), true, 512, JSON_THROW_ON_ERROR);

        $receivers = $decoded['receivers'] ?? [];
        $limitRaw = $decoded['--limit'] ?? 1;
        $timeLimitRaw = $decoded['--time-limit'] ?? self::TIME_LIMIT_SECONDS;
        $limit = \is_numeric($limitRaw) ? (int) $limitRaw : 1;
        $timeLimit = \is_numeric($timeLimitRaw) ? (int) $timeLimitRaw : self::TIME_LIMIT_SECONDS;

        $names = \is_array($receivers) ? \array_values($receivers) : [$receivers];

        $this->runWorker($names, $limit, $timeLimit, $this->verbosityFrom($decoded));
    }

    /**
     * Kept registered and refusing, so a scenario reaching for the old phrasing is handed the
     * canonical one rather than "step undefined", and nobody re-adds it believing it is missing.
     *
     * It could not stay: these two phrases claimed the generic wording for a Messenger worker purely
     * because this context defined them first, which left the console context to invent
     * "the last command …" for the same three assertions. Two vocabularies for one claim, each
     * half-used, and the generic one naming a subject that does not exist here — nothing this step
     * ever touched was a command. {@see RunOutcomeContext} owns them now.
     */
    #[Then('the command should succeed')]
    public function theCommandShouldSucceed(): never
    {
        $this->refuseSupersededPhrase('the last run should succeed');
    }

    /**
     * Superseded — refuses. See {@see theCommandShouldSucceed()}.
     */
    #[Then('the output should contain :text')]
    public function theOutputShouldContain(string $text): never
    {
        $this->refuseSupersededPhrase(\sprintf('the last run output should contain "%s"', $text));
    }

    /**
     * Runs the message through its registered handlers in-process — the lightweight way to assert a
     * handler's side effects without running the worker loop. Uses the (retained) `getSent()` log, so
     * it works after the pending queue has been drained by a real consume.
     */
    #[Then('message :number sent to :transportName is processed')]
    public function messageSentIsProcessed(int $number, string $transportName): void
    {
        $envelope = $this->sentEnvelope($transportName, $number);

        $handlersRan = 0;

        /** @var HandlerDescriptor $handler */
        foreach ($this->handlersLocator->getHandlers($envelope) as $handler) {
            $handler->getHandler()($envelope->getMessage());
            ++$handlersRan;
        }

        self::assertGreaterThanOrEqual(
            1,
            $handlersRan,
            \sprintf('No handler found for message %d on transport "%s"', $number, $transportName),
        );
    }

    /**
     * Asserts how many messages are pending on a transport without consuming them — `get()` is a
     * non-destructive read of the in-memory queue. This is the behavioural guard for the audit
     * deduplication invariant: a canonical route must enqueue exactly one audit message (its explicit
     * entry), and the generic access-log hook must not add a second.
     */
    #[Then('the :transportName transport should hold :count message')]
    #[Then('the :transportName transport should hold :count messages')]
    public function theTransportShouldHold(string $transportName, int $count): void
    {
        self::assertCount(
            $count,
            $this->transports->pending($transportName),
            \sprintf('Unexpected number of pending messages on transport "%s"', $transportName),
        );
    }

    /**
     * A consume the scenario named a count for has to have read that many messages. Fewer pending than
     * asked for is not a shorter run: the time-limit listener stops the worker, nothing throws and the
     * exit code is 0, so the step reports success over a queue it never drained.
     *
     * Only the `I consume N …` phrasings go through here. {@see iExecuteCommandWithOptions()} models raw
     * `messenger:consume` parity, where `--limit` is a ceiling and timing out is a legitimate outcome.
     */
    private function consumeExactly(int $count, string $transportName, int $timeLimit): void
    {
        $consumed = $this->runWorker(
            [$transportName],
            $count,
            $timeLimit,
            OutputInterface::VERBOSITY_VERY_VERBOSE,
        );

        self::assertSame($count, $consumed, \sprintf(
            'Asked for %d message(s) from transport "%s" and the worker consumed %d before stopping on '
            . 'its time limit, so this step asserted nothing.',
            $count,
            $transportName,
            $consumed,
        ));
    }

    /**
     * The strongest flag present wins. Resolving by position would not: the map spells very-verbose on both
     * sides of `-vvv`, so `{"-vvv": true, "--verbose": true}` would consume one level below what it asked
     * for, and a run degrading its own verbosity is invisible in the output it then fails to produce.
     *
     * @param array<string, mixed> $decoded
     */
    private function verbosityFrom(array $decoded): int
    {
        $verbosity = OutputInterface::VERBOSITY_NORMAL;

        foreach (self::VERBOSITY_FLAGS as $flag => $level) {
            if (\array_key_exists($flag, $decoded)) {
                $verbosity = \max($verbosity, $level);
            }
        }

        return $verbosity;
    }

    /**
     * @param list<mixed> $transportNames
     *
     * @return int how many messages the run took off the transports — handled plus failed
     */
    private function runWorker(array $transportNames, int $limit, int $timeLimit, int $verbosity): int
    {
        $receivers = [];
        $unusable = [];

        foreach ($transportNames as $transportName) {
            if (!\is_string($transportName)) {
                $unusable[] = \get_debug_type($transportName);

                continue;
            }

            $receivers[$transportName] = $this->transports->receiver($transportName);
        }

        // A worker holding no receiver reads nothing, spins to its time limit and reports success, so
        // every assertion standing on the run would pass over a consume that never touched a transport.
        self::assertNotSame([], $receivers, \sprintf(
            'No transport name resolved to a receiver (unusable: %s)',
            [] === $unusable ? 'none given' : \implode(', ', $unusable),
        ));

        $output = new BufferedOutput($verbosity);
        $logger = new ConsoleLogger($output);

        // A private dispatcher carries only this run's stop conditions — no accumulation across
        // scenarios on a shared dispatcher, and the bus (hence the handlers) runs independently of it.
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($limit, $logger));
        $dispatcher->addSubscriber(new StopWorkerOnTimeLimitListener($timeLimit, $logger));

        $consumed = 0;
        $countOne = static function () use (&$consumed): void {
            ++$consumed;
        };
        $dispatcher->addListener(WorkerMessageHandledEvent::class, $countOne);
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $countOne);

        $worker = new Worker($receivers, $this->routableMessageBus, $dispatcher, $logger);

        $exitCode = 0;

        try {
            $worker->run(['sleep' => 50_000]);
        } catch (Throwable $throwable) {
            // A Worker has no exit code of its own; "it threw" is the only failure signal there is,
            // and RunOutcomeContext documents that as what "the last run should fail" means here.
            $exitCode = 1;
            $output->writeln($throwable->getMessage());
        }

        $this->lastRun->record($exitCode, $output->fetch());

        return $consumed;
    }

    private function refuseSupersededPhrase(string $canonical): never
    {
        throw new RuntimeException(\sprintf(
            'This phrasing named a console command for something that is a Messenger worker run, and '
            . 'split one assertion across two vocabularies. Use: %s',
            $canonical,
        ));
    }

    private function sentEnvelope(string $transportName, int $number): Envelope
    {
        $envelope = $this->transports->sent($transportName)[$number - 1] ?? null;

        self::assertInstanceOf(
            Envelope::class,
            $envelope,
            \sprintf('Message %d not found on transport "%s"', $number, $transportName),
        );

        return $envelope;
    }
}
