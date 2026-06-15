<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Gherkin\Node\TableNode;
use Behat\Hook\BeforeScenario;
use Behat\Step\Then;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * Asserts the structured log line of the RFC 9457 error contract declaratively. Reads the
 * {@see BufferingLogger} that `services_test.yaml` injects into
 * {@see \Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder} — the same instance
 * the listener writes its one-line-per-error record to, so a scenario can pin the level, message
 * and SRE context fields (`exception_category`, `type`, …) without parsing log files.
 *
 * The injection is scoped to `ExceptionResponder` only (the test wiring deliberately does not alias
 * `LoggerInterface` globally), so this context sees the error-contract stream and not framework noise.
 *
 * @phpstan-type LogRecord array{0: mixed, 1: string, 2: array<string, mixed>}
 */
final class LoggerContext extends AbstractContext
{
    /**
     * Records drained from the buffer so far this scenario. {@see BufferingLogger::cleanLogs()}
     * empties the buffer on read, so each access drains-and-appends rather than re-reading — that
     * keeps every record visible across multiple request/assert cycles in one scenario.
     *
     * @var list<LogRecord>
     */
    private array $records = [];

    public function __construct(private readonly BufferingLogger $logger)
    {
    }

    #[BeforeScenario]
    public function resetLogBuffer(): void
    {
        $this->logger->cleanLogs();
        $this->records = [];
    }

    #[Then('the logger logged the message :message')]
    public function theLoggerLoggedTheMessage(string $message): void
    {
        self::assertNotNull(
            $this->findRecord($message, null),
            \sprintf('No log entry with message "%s" was recorded', $message),
        );
    }

    #[Then('the logger logged a log entry as :level with message :message')]
    public function theLoggerLoggedAtLevel(string $level, string $message): void
    {
        self::assertNotNull(
            $this->findRecord($message, $level),
            \sprintf('No log entry "%s" at level "%s" was recorded', $message, $level),
        );
    }

    #[Then('the logger did not log a log entry as :level with message :message')]
    public function theLoggerDidNotLogAtLevel(string $level, string $message): void
    {
        self::assertNull(
            $this->findRecord($message, $level),
            \sprintf('A log entry "%s" at level "%s" was recorded but should not have been', $message, $level),
        );
    }

    #[Then('the logger logged a log entry as :level with message :message and context contains:')]
    public function theLoggerLoggedWithContextContaining(string $level, string $message, TableNode $table): void
    {
        $record = $this->findRecord($message, $level);

        self::assertNotNull(
            $record,
            \sprintf('No log entry "%s" at level "%s" was recorded', $message, $level),
        );

        $context = $record[2];

        foreach ($table->getRowsHash() as $key => $expected) {
            self::assertArrayHasKey($key, $context, \sprintf('Log context is missing key "%s"', $key));
            $expectedValue = \is_array($expected) ? \implode('', $expected) : $expected;
            $actual = $this->stringify($context[$key]);
            self::assertSame(
                $expectedValue,
                $actual,
                \sprintf('Log context "%s" is "%s" but "%s" was expected', $key, $actual, $expectedValue),
            );
        }
    }

    private function stringify(mixed $value): string
    {
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return null === $value ? '' : (\json_encode($value) ?: '');
    }

    /**
     * @return LogRecord|null
     */
    private function findRecord(string $message, ?string $level): ?array
    {
        foreach ($this->logs() as $record) {
            if ($record[1] !== $message) {
                continue;
            }

            if (null === $level || $record[0] === $level) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return list<LogRecord>
     */
    private function logs(): array
    {
        /** @var list<LogRecord> $drained */
        $drained = $this->logger->cleanLogs();

        foreach ($drained as $record) {
            $this->records[] = $record;
        }

        return $this->records;
    }
}
