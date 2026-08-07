<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Monitoring\Infrastructure\Monolog;

use DateTimeImmutable;
use Erpify\Shared\Monitoring\Infrastructure\Monolog\RequestUriRedactionProcessor;
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
#[CoversClass(RequestUriRedactionProcessor::class)]
final class RequestUriRedactionProcessorTest extends TestCase
{
    /**
     * The shape Symfony's router listener writes, which is the emitter that made this processor necessary:
     * an absolute URI with the query intact, at INFO, on a channel prod buffers and flushes on any 5xx.
     */
    #[Test]
    #[DataProvider('provideItRedactsTheIdentityAxesOfTheLoggedUriCases')]
    public function itRedactsTheIdentityAxesOfTheLoggedUri(string $requestUri, string $expected): void
    {
        $processed = (new RequestUriRedactionProcessor())($this->recordWith(['request_uri' => $requestUri]));

        $this->assertSame($expected, $processed->context['request_uri'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideItRedactsTheIdentityAxesOfTheLoggedUriCases(): iterable
    {
        yield 'audit document URL' => [
            'https://erpify.test/backoffice/audit?actorId=8f14e45f-ceea-467a-9c1e-9e5b1c2a3d4e&level=security',
            'https://erpify.test/backoffice/audit?actorId=REDACTED&level=security',
        ];
        yield 'positional search grammar, percent-encoded as it travels' => [
            'https://erpify.test/api/v1/backoffice/audit?filters%5B0%5D%5Bvalue%5D=8f14e45f-ceea-467a-9c1e',
            'https://erpify.test/api/v1/backoffice/audit?filters%5B0%5D%5Bvalue%5D=REDACTED',
        ];
        yield 'single-use invitation secret' => [
            'https://erpify.test/accept-invitation?token=01920000-0000-7000-8000-000000000000.s3cr3t',
            'https://erpify.test/accept-invitation?token=REDACTED',
        ];
        yield 'no query string at all' => [
            'https://erpify.test/api/v1/backoffice/banks',
            'https://erpify.test/api/v1/backoffice/banks',
        ];
    }

    /**
     * The residual is declared, so it must stay observable rather than be closed on one sink and left open on
     * the other two: a reader comparing the access log with this one has to see the same id in both.
     */
    #[Test]
    public function itLeavesAPersonIdInThePathAlone(): void
    {
        $uri = 'https://erpify.test/api/v1/backoffice/users/8f14e45f-ceea-467a-9c1e-9e5b1c2a3d4e';

        $processed = (new RequestUriRedactionProcessor())($this->recordWith(['request_uri' => $uri]));

        $this->assertSame($uri, $processed->context['request_uri'] ?? null);
    }

    /**
     * Every other record on every other channel passes through this processor too, so it has to be inert on
     * the ones that carry no URI — including a context whose `request_uri` is not a string, which no emitter
     * writes today and none is prevented from writing tomorrow.
     *
     * @param array<string, mixed> $context
     */
    #[Test]
    #[DataProvider('provideItLeavesARecordItCannotRedactUntouchedCases')]
    public function itLeavesARecordItCannotRedactUntouched(array $context): void
    {
        $processed = (new RequestUriRedactionProcessor())($this->recordWith($context));

        $this->assertSame($context, $processed->context);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideItLeavesARecordItCannotRedactUntouchedCases(): iterable
    {
        yield 'no request_uri' => [['route' => 'backoffice_bank_search', 'method' => 'GET']];
        yield 'empty context' => [[]];
        yield 'request_uri is not a string' => [['request_uri' => ['https://erpify.test/?actorId=x']]];
    }

    /**
     * A PSR-7 `UriInterface` is `Stringable`, and the formatter downstream stringifies it with its query
     * intact — so a processor that accepted only `string` would cover the emitters we know about and let the
     * next library straight through.
     */
    #[Test]
    public function itRedactsAUriObjectTheFormatterWouldStringifyLater(): void
    {
        $uri = new class implements Stringable {
            #[Override]
            public function __toString(): string
            {
                return 'https://erpify.test/backoffice/audit?actorId=8f14e45f-ceea&level=security';
            }
        };

        $processed = (new RequestUriRedactionProcessor())($this->recordWith(['request_uri' => $uri]));

        $this->assertSame(
            'https://erpify.test/backoffice/audit?actorId=REDACTED&level=security',
            $processed->context['request_uri'] ?? null,
        );
    }

    /**
     * Redaction must not cost the operator the rest of the line: the sibling keys are what make a flushed
     * buffer readable, and a processor that rebuilt the context would drop them without any test noticing.
     */
    #[Test]
    public function itPreservesEverySiblingKeyOfTheRedactedField(): void
    {
        $record = $this->recordWith([
            'route' => 'backoffice_audit_search',
            'request_uri' => 'https://erpify.test/api/v1/backoffice/audit?actorId=8f14e45f-ceea',
            'method' => 'GET',
        ]);

        $processed = (new RequestUriRedactionProcessor())($record);

        $this->assertSame('backoffice_audit_search', $processed->context['route'] ?? null);
        $this->assertSame('GET', $processed->context['method'] ?? null);
        $this->assertSame('Matched route.', $processed->message);
        $this->assertSame('request', $processed->channel);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function recordWith(array $context): LogRecord
    {
        return new LogRecord(
            new DateTimeImmutable('2026-08-07T09:56:25+00:00'),
            'request',
            Level::Info,
            'Matched route.',
            $context,
        );
    }
}
