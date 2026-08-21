<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Monitoring\Infrastructure\Monolog;

use DateTimeImmutable;
use Erpify\Shared\Monitoring\Infrastructure\Monolog\ConsoleCommandRedactionProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;

/**
 * @internal
 */
#[CoversClass(ConsoleCommandRedactionProcessor::class)]
final class ConsoleCommandRedactionProcessorTest extends TestCase
{
    /**
     * The shapes that made this processor necessary, spelled as `ArgvInput::__toString()` renders them —
     * tokens joined by a space, anything outside `[\w-]` wrapped in single quotes by `escapeToken`.
     */
    #[Test]
    #[DataProvider('provideItKeepsTheCommandNameAndDropsEveryArgumentCases')]
    public function itKeepsTheCommandNameAndDropsEveryArgument(string $argv, string $expected): void
    {
        $processed = (new ConsoleCommandRedactionProcessor())($this->recordWith(['command' => $argv]));

        $this->assertSame($expected, $processed->context['command'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideItKeepsTheCommandNameAndDropsEveryArgumentCases(): iterable
    {
        yield 'a person id, in the record of the erasure that id was to end' => [
            'identity:gdpr:erase-subject 0193a1f2-7c4d-7e21-9b3a-8f14e45fceea',
            'identity:gdpr:erase-subject REDACTED',
        ];
        yield 'a plaintext password beside the address it belongs to' => [
            "organization:administrator:create 'admin@example.test' 'C0rrect-Horse-Battery'",
            'organization:administrator:create REDACTED',
        ];
        yield 'an invited person address' => [
            "iam:invitation:create 'someone@example.test' ADMIN",
            'iam:invitation:create REDACTED',
        ];
        yield 'options after the name go with everything else' => [
            'audit:gdpr:erase 0193a1f2-7c4d-7e21-9b3a-8f14e45fceea --no-interaction',
            'audit:gdpr:erase REDACTED',
        ];
        yield 'a quoted argument holding a space is not split back into the line' => [
            "organization:provision 'Acme Sociedad Limitada'",
            'organization:provision REDACTED',
        ];
        yield 'an option value attached with = is an argument like any other' => [
            'messenger:consume async --time-limit=3600',
            'messenger:consume REDACTED',
        ];
    }

    /**
     * The sentinel asserts that arguments were hidden. A command that took none is a different fact, and
     * stamping the token over it would report a redaction that never happened.
     */
    #[Test]
    public function itLeavesACommandThatTookNoArgumentsExactlyAsItWas(): void
    {
        $record = $this->recordWith(['command' => 'audit:gdpr:reconcile-erasures']);

        $processed = (new ConsoleCommandRedactionProcessor())($record);

        $this->assertSame('audit:gdpr:reconcile-erasures', $processed->context['command'] ?? null);
    }

    /**
     * The declared degradation, pinned so it stays a decision rather than becoming a surprise: past a leading
     * option the name can only be located with the command's own input definition — whether `-e` consumes the
     * next token — which this processor does not have. It redacts whole instead of guessing.
     */
    #[Test]
    #[DataProvider('provideItRedactsWholeWhenTheNameCannotBeLocatedCases')]
    public function itRedactsWholeWhenTheNameCannotBeLocated(string $argv): void
    {
        $processed = (new ConsoleCommandRedactionProcessor())($this->recordWith(['command' => $argv]));

        $this->assertSame('REDACTED', $processed->context['command'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideItRedactsWholeWhenTheNameCannotBeLocatedCases(): iterable
    {
        yield 'separated global option, whose next token is ambiguous' => [
            '--env prod identity:gdpr:erase-subject 0193a1f2-7c4d-7e21-9b3a-8f14e45fceea',
        ];
        yield 'attached global option, refused by the same rule rather than by a second one' => [
            '--env=prod identity:gdpr:erase-subject 0193a1f2-7c4d-7e21-9b3a-8f14e45fceea',
        ];
        yield 'short verbosity flag' => ['-vvv iam:invitation:create someone@example.test'];
    }

    /**
     * Every record on every channel passes through this processor, so it has to be inert on the ones carrying
     * no argv — including a `command` that is not a string, which no emitter writes today and none is
     * prevented from writing tomorrow.
     *
     * @param array<string, mixed> $context
     */
    #[Test]
    #[DataProvider('provideItLeavesARecordItCannotRedactUntouchedCases')]
    public function itLeavesARecordItCannotRedactUntouched(array $context): void
    {
        $processed = (new ConsoleCommandRedactionProcessor())($this->recordWith($context));

        $this->assertSame($context, $processed->context);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideItLeavesARecordItCannotRedactUntouchedCases(): iterable
    {
        yield 'no command key' => [['route' => 'backoffice_bank_search', 'method' => 'GET']];
        yield 'empty context' => [[]];
        yield 'command is not a string' => [['command' => ['identity:gdpr:erase-subject', '0193a1f2']]];
        yield 'command is an empty string' => [['command' => '']];
    }

    /**
     * The formatter stringifies a `Stringable` downstream of every processor, so accepting only `string`
     * would cover the emitter measured here and let the next one write the argv straight through.
     */
    #[Test]
    public function itRedactsAnArgvObjectTheFormatterWouldStringifyLater(): void
    {
        $argv = new class implements Stringable {
            #[Override]
            public function __toString(): string
            {
                return 'identity:gdpr:erase-subject 0193a1f2-7c4d-7e21-9b3a-8f14e45fceea';
            }
        };

        $processed = (new ConsoleCommandRedactionProcessor())($this->recordWith(['command' => $argv]));

        $this->assertSame('identity:gdpr:erase-subject REDACTED', $processed->context['command'] ?? null);
    }

    /**
     * Redaction must not cost the operator the rest of the line: `exception`, `message` and `code` are what
     * make a flushed buffer readable, and a processor that rebuilt the context would drop them with no test
     * noticing.
     */
    #[Test]
    public function itPreservesEverySiblingKeyOfTheRedactedField(): void
    {
        $record = $this->recordWith([
            'exception' => new \RuntimeException('handler blew up'),
            'command' => 'identity:gdpr:erase-subject 0193a1f2-7c4d-7e21-9b3a-8f14e45fceea',
            'message' => 'handler blew up',
            'code' => 1,
        ]);

        $processed = (new ConsoleCommandRedactionProcessor())($record);

        $this->assertSame('handler blew up', $processed->context['message'] ?? null);
        $this->assertSame(1, $processed->context['code'] ?? null);
        $this->assertInstanceOf(\RuntimeException::class, $processed->context['exception'] ?? null);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function recordWith(array $context): LogRecord
    {
        return new LogRecord(
            new DateTimeImmutable(),
            'console',
            Level::Critical,
            'Error thrown while running command "{command}". Message: "{message}"',
            $context,
        );
    }
}
