<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Step\Then;
use Behat\Step\When;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\Messenger\MessengerTransports;
use JsonException;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;
use Throwable;

/**
 * Drives a real Messenger {@see Worker} so a scenario can assert the effects only the full consuming
 * pipeline produces — the receiving-side bus middleware (idempotency claim in `handled_domain_event`)
 * and the handlers themselves (notification email, realtime publish).
 *
 * The worker is constructed against the very transport instance the rest of the suite reads, then run
 * with a message-limit and a time-limit backstop on a private dispatcher. This deliberately bypasses
 * `messenger:consume` via a booted console application: that path resets `ResetInterface` services —
 * the `in-memory://` transport among them — wiping the queued message before the worker reads it, so
 * no handler would run. Running the `Worker` class directly keeps the queued message and is still the
 * real consume (same worker, bus middleware and handlers), only without the CLI wrapper.
 *
 * The worker's own logger is a {@see ConsoleLogger} over a buffer, so `the command should succeed` and
 * `the output should contain` can assert it ran (e.g. the "handled successfully" acknowledgement).
 */
final class MessengerConsumerContext extends AbstractContext
{
    /**
     * Backstop wall-clock budget (seconds) for a single consume, so an over-stated message limit
     * degrades to "stop after a few seconds" instead of blocking the suite forever.
     */
    private const int TIME_LIMIT_SECONDS = 5;

    /**
     * @var array<string, int>
     */
    private const array VERBOSITY_FLAGS = [
        '-v' => OutputInterface::VERBOSITY_VERBOSE,
        '-vv' => OutputInterface::VERBOSITY_VERY_VERBOSE,
        '-vvv' => OutputInterface::VERBOSITY_DEBUG,
        '--verbose' => OutputInterface::VERBOSITY_VERY_VERBOSE,
    ];

    private ?int $lastExitCode = null;

    private string $lastOutput = '';

    public function __construct(
        private readonly MessengerTransports $transports,
        #[Autowire(service: 'messenger.routable_message_bus')]
        private readonly MessageBusInterface $routableMessageBus,
    ) {
    }

    #[When('I consume :count message from the :transportName transport')]
    #[When('I consume :count messages from the :transportName transport')]
    public function iConsume(int $count, string $transportName): void
    {
        $this->runWorker([$transportName], $count, self::TIME_LIMIT_SECONDS, OutputInterface::VERBOSITY_VERY_VERBOSE);
    }

    #[When('I consume :count message from the :transportName transport with time limit :seconds')]
    #[When('I consume :count messages from the :transportName transport with time limit :seconds')]
    public function iConsumeWithTimeLimit(int $count, string $transportName, int $seconds): void
    {
        $this->runWorker([$transportName], $count, $seconds, OutputInterface::VERBOSITY_VERY_VERBOSE);
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

        $verbosity = OutputInterface::VERBOSITY_NORMAL;

        foreach (self::VERBOSITY_FLAGS as $flag => $level) {
            if (\array_key_exists($flag, $decoded)) {
                $verbosity = $level;
            }
        }

        $names = \is_array($receivers) ? \array_values($receivers) : [$receivers];

        $this->runWorker($names, $limit, $timeLimit, $verbosity);
    }

    #[Then('the command should succeed')]
    public function theCommandShouldSucceed(): void
    {
        self::assertNotNull($this->lastExitCode, 'No consume has been executed yet');
        self::assertSame(
            0,
            $this->lastExitCode,
            \sprintf('Consume failed (code %d). Output:%s%s', $this->lastExitCode, PHP_EOL, $this->lastOutput),
        );
    }

    #[Then('the output should contain :text')]
    public function theOutputShouldContain(string $text): void
    {
        self::assertStringContainsString(
            $text,
            $this->lastOutput,
            \sprintf('Consume output did not contain "%s". Output:%s%s', $text, PHP_EOL, $this->lastOutput),
        );
    }

    /**
     * @param list<mixed> $transportNames
     */
    private function runWorker(array $transportNames, int $limit, int $timeLimit, int $verbosity): void
    {
        $receivers = [];

        foreach ($transportNames as $transportName) {
            if (!\is_string($transportName)) {
                continue;
            }

            $receivers[$transportName] = $this->transports->receiver($transportName);
        }

        $output = new BufferedOutput($verbosity);
        $logger = new ConsoleLogger($output);

        // A private dispatcher carries only this run's stop conditions — no accumulation across
        // scenarios on a shared dispatcher, and the bus (hence the handlers) runs independently of it.
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($limit, $logger));
        $dispatcher->addSubscriber(new StopWorkerOnTimeLimitListener($timeLimit, $logger));

        $worker = new Worker($receivers, $this->routableMessageBus, $dispatcher, $logger);

        try {
            $worker->run(['sleep' => 50_000]);
            $this->lastExitCode = 0;
        } catch (Throwable $throwable) {
            $this->lastExitCode = 1;
            $output->writeln($throwable->getMessage());
        }

        $this->lastOutput = $output->fetch();
    }
}
