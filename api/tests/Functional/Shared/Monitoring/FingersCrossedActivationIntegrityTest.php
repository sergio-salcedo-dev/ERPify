<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Monitoring;

use DateTimeImmutable;
use Monolog\Handler\FingersCrossed\ErrorLevelActivationStrategy;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Monolog\Handler\FingersCrossed\HttpCodeActivationStrategy;
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
 * then activates on a request the configuration excludes by name, and flushes its whole buffer of preceding
 * records to `php://stderr`: the sink with no rotation, no TTL and no owner of erasure that the redaction
 * rules in this module exist to keep identifiers out of.
 *
 * The processors are read from the booted container rather than listed here, so this holds for whichever ones
 * the application enrols — including one added after this file was written, which is the only version of the
 * assertion worth having.
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

        $record = $this->aNotFoundRecord();

        foreach ($this->enrolledProcessors() as $processor) {
            $record = $processor($record);
        }

        $this->assertInstanceOf(
            Throwable::class,
            $record->context['exception'] ?? null,
            'a logger processor replaced the throwable the activation strategy reads',
        );
    }

    #[Test]
    public function anExcludedHttpCodeDoesNotFlushTheBuffer(): void
    {
        self::bootKernel();

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/banks/does-not-exist'));

        $sink = new TestHandler();
        $handler = new FingersCrossedHandler(
            $sink,
            new HttpCodeActivationStrategy(
                $requestStack,
                [['code' => 404, 'urls' => []]],
                new ErrorLevelActivationStrategy(Level::Error),
            ),
            self::BUFFER_SIZE,
        );

        $logger = new Logger('app', [$handler], $this->enrolledProcessors());
        $logger->error('Uncaught PHP Exception', ['exception' => new NotFoundHttpException()]);

        $this->assertSame([], $sink->getRecords(), 'a 404 flushed the buffer into the unrotated sink');
    }

    /**
     * @return list<callable(LogRecord): LogRecord>
     */
    private function enrolledProcessors(): array
    {
        $logger = self::getContainer()->get('monolog.logger');
        $this->assertInstanceOf(Logger::class, $logger);

        $processors = \array_values($logger->getProcessors());
        $this->assertNotEmpty($processors, 'nothing is enrolled, so this test asserts nothing');

        return $processors;
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
