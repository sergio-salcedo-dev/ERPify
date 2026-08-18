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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

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
    private const int BUFFER_SIZE = 50;

    #[Test]
    public function noEnrolledProcessorHidesAnHttpExceptionFromTheActivationStrategy(): void
    {
        self::bootKernel();

        $channels = $this->channelLoggers();
        $this->assertNotEmpty($channels, 'no channel logger was read, so this asserts nothing');

        foreach ($channels as $id => $logger) {
            $record = $this->aNotFoundRecord();

            foreach ($logger->getProcessors() as $processor) {
                $record = $processor($record);
            }

            $this->assertInstanceOf(
                Throwable::class,
                $record->context['exception'] ?? null,
                \sprintf('a processor on "%s" replaced the throwable the activation strategy reads', $id),
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

        $sink = new TestHandler();
        $handler = new FingersCrossedHandler($sink, $strategy, self::BUFFER_SIZE);

        $logger = new Logger('app', [$handler], $this->processorsOfTheAppChannel());
        $logger->error('Uncaught PHP Exception', ['exception' => new NotFoundHttpException()]);

        $this->assertSame([], $sink->getRecords(), 'a 404 flushed the buffer into the unrotated sink');
    }

    /**
     * The strategy the deployed `main` handler actually holds, so deleting `excluded_http_codes` from
     * `monolog.yaml` turns this red instead of leaving it asserting a literal of its own.
     */
    private function configuredActivationStrategy(): ActivationStrategyInterface
    {
        $handler = self::getContainer()->get('monolog.handler.main');
        $this->assertInstanceOf(FingersCrossedHandler::class, $handler);

        $strategy = (new ReflectionProperty($handler, 'activationStrategy'))->getValue($handler);
        $this->assertInstanceOf(ActivationStrategyInterface::class, $strategy);

        return $strategy;
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

    private function aNotFoundRecord(): LogRecord
    {
        return new LogRecord(
            new DateTimeImmutable(),
            'app',
            Level::Error,
            'Uncaught PHP Exception',
            ['exception' => new NotFoundHttpException()],
        );
    }
}
