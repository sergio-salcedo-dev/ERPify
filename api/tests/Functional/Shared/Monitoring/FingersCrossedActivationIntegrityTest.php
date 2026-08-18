<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Monitoring;

use DateTimeImmutable;
use Monolog\Handler\FingersCrossed\ActivationStrategyInterface;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `excluded_http_codes` decides what stays out of an unrotated sink, and it decides it by reading an OBJECT.
 *
 * `HttpCodeActivationStrategy` asks whether `context['exception']` is an `HttpExceptionInterface`. Logger
 * processors run before any handler sees the record, so a processor that replaces that Throwable with an
 * array — a normalised map, a string, anything — leaves the strategy unable to recognise a 404. The handler
 * then activates on a request the configuration excludes by name and flushes its whole buffer of preceding
 * records to `php://stderr`: the sink with no rotation, no TTL and no owner of erasure.
 *
 * **Both halves are read from the container**, because a test that names its own processors proves nothing
 * about the application's, and a test that builds its own activation strategy from a literal stays green while
 * the configured exclusion is deleted. What is asserted here is the deployed configuration, not a copy of it.
 *
 * The remaining blind spot, stated rather than implied: this reads processors enrolled on CHANNEL LOGGERS. A
 * processor tagged `handler: main` lands on the gate handler itself, which `FingersCrossedHandler::handle()`
 * runs before consulting the strategy, and no assertion here would see it.
 *
 * @internal
 */
#[CoversNothing]
final class FingersCrossedActivationIntegrityTest extends KernelTestCase
{
    /** The code the deployed exclusion names, spelled as the configuration spells it. */
    private const int EXCLUDED_STATUS = 404;

    /** @var list<RequestStack> */
    private array $popped = [];

    #[Test]
    public function noEnrolledProcessorHidesAnHttpExceptionFromTheActivationStrategy(): void
    {
        self::bootKernel();

        $channels = $this->channelLoggers();
        $this->assertNotEmpty($channels, 'no channel logger was read, so this asserts nothing');

        foreach ($channels as $id => $logger) {
            // The record carries the channel of the logger about to process it: a processor that branches on
            // `$record->channel` would otherwise run here under a name it never sees in production, take the
            // wrong branch, and leave this assertion green over the very processor that blinds the strategy.
            $record = $this->aNotFoundRecord($logger->getName());

            foreach ($logger->getProcessors() as $processor) {
                $record = $processor($record);
            }

            $exception = $record->context['exception'] ?? null;

            // `HttpExceptionInterface` and not `Throwable`, because that is what the strategy asks for: a
            // processor that wrapped the 404 in a plain exception, preserving it as `previous`, would satisfy
            // the looser assertion and still blind the exclusion.
            $this->assertInstanceOf(
                HttpExceptionInterface::class,
                $exception,
                \sprintf('a processor on "%s" replaced the throwable the activation strategy reads', $id),
            );
            $this->assertSame(
                self::EXCLUDED_STATUS,
                $exception->getStatusCode(),
                \sprintf('a processor on "%s" changed the status the exclusion matches on', $id),
            );
        }
    }

    #[Test]
    public function theConfiguredExclusionKeepsAnExcludedCodeOutOfTheSink(): void
    {
        self::bootKernel();

        $strategy = $this->configuredActivationStrategy();

        // The stack the strategy itself holds, not the one the container hands out under another id: an
        // exclusion is only evaluated when `getMainRequest()` answers, so pushing onto a different instance
        // would make this pass for the wrong reason — by never reaching the exclusion at all.
        $requestStack = (new ReflectionProperty($strategy, 'requestStack'))->getValue($strategy);
        $this->assertInstanceOf(RequestStack::class, $requestStack);
        $requestStack->push(Request::create('/api/v1/banks/does-not-exist'));
        // The stack is the container's, shared with whatever runs next in this kernel.
        $this->popped[] = $requestStack;

        $sink = new TestHandler();
        $handler = new FingersCrossedHandler($sink, $strategy, $this->deployedBufferSize());

        $logger = new Logger('app', [$handler], $this->processorsOfTheAppChannel());
        $logger->error('Uncaught PHP Exception', ['exception' => new NotFoundHttpException()]);

        $this->assertSame([], $sink->getRecords(), 'a 404 flushed the buffer into the unrotated sink');
    }

    /**
     * The strategy the deployed `main` handler actually holds, so deleting `excluded_http_codes` from
     * `monolog.yaml` turns this red instead of leaving it asserting a literal of its own.
     */
    /**
     * The exclusion is declared once per environment, and only the `test` one is reachable from a booted test
     * kernel — so the assertion above holds while the `prod` block is deleted or narrowed, and production
     * flushes its buffer on every 404 with this file green. Comparing the declarations closes that: it is the
     * one property of the deployed configuration a test in this environment can actually observe.
     */
    #[Test]
    public function everyEnvironmentDeclaresTheSameExclusion(): void
    {
        $configured = \file_get_contents($this->monologConfigPath());
        $this->assertIsString($configured);

        $matched = \preg_match_all('/^\s*excluded_http_codes:\s*(.+)$/m', $configured, $matches);

        $this->assertGreaterThan(1, $matched, 'fewer declarations than environments, so one lost its exclusion');
        $this->assertSame(
            [\reset($matches[1])],
            \array_values(\array_unique($matches[1])),
            'the environments disagree on which codes stay out of the unrotated sink',
        );
    }

    private function monologConfigPath(): string
    {
        return \dirname(__DIR__, 4) . '/config/packages/monolog.yaml';
    }

    protected function tearDown(): void
    {
        foreach ($this->popped as $requestStack) {
            $requestStack->pop();
        }

        $this->popped = [];
        parent::tearDown();
    }

    private function configuredActivationStrategy(): ActivationStrategyInterface
    {
        $handler = self::getContainer()->get('monolog.handler.main');
        $this->assertInstanceOf(FingersCrossedHandler::class, $handler);

        $strategy = (new ReflectionProperty($handler, 'activationStrategy'))->getValue($handler);
        $this->assertInstanceOf(ActivationStrategyInterface::class, $strategy);

        return $strategy;
    }

    /**
     * Read off the deployed handler rather than copied, so a change to `buffer_size` cannot leave this test
     * exercising a size the application does not use.
     */
    private function deployedBufferSize(): int
    {
        $handler = self::getContainer()->get('monolog.handler.main');
        $this->assertInstanceOf(FingersCrossedHandler::class, $handler);

        $bufferSize = (new ReflectionProperty($handler, 'bufferSize'))->getValue($handler);
        $this->assertIsInt($bufferSize);

        return $bufferSize;
    }

    /**
     * @return array<string, Logger>
     */
    private function channelLoggers(): array
    {
        $container = self::getContainer();
        $loggers = [];

        foreach ($container->getServiceIds() as $id) {
            if (!\str_starts_with($id, 'monolog.logger')) {
                continue;
            }

            $service = $container->get($id);

            if ($service instanceof Logger) {
                $loggers[$id] = $service;
            }
        }

        return $loggers;
    }

    /**
     * @return list<callable(LogRecord): LogRecord>
     */
    private function processorsOfTheAppChannel(): array
    {
        $logger = self::getContainer()->get('monolog.logger');
        $this->assertInstanceOf(Logger::class, $logger);

        return \array_values($logger->getProcessors());
    }

    private function aNotFoundRecord(string $channel = 'app'): LogRecord
    {
        return new LogRecord(
            new DateTimeImmutable(),
            $channel,
            Level::Error,
            'Uncaught PHP Exception',
            ['exception' => new NotFoundHttpException()],
        );
    }
}
