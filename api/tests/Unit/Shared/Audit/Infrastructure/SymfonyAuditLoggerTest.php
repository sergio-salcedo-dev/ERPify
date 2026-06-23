<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure;

use DateTimeImmutable;
use Erpify\Shared\Audit\Application\RecordAuditEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Infrastructure\SymfonyAuditLogger;
use Erpify\Shared\Http\Infrastructure\CorrelationIdListener;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FakeMessageBus;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedActorContextFactory;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedClock;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\InMemoryAuditLogWriter;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(SymfonyAuditLogger::class)]
final class SymfonyAuditLoggerTest extends TestCase
{
    private const string CORRELATION_ID = '019877c2-1f3a-7b8c-8d2e-1a2b3c4d5e6f';

    private const string OCCURRED_ON = '2026-06-23T12:34:56.123456+00:00';

    public function testActivitySealsTheContextAndDispatchesWithoutWriting(): void
    {
        $bus = new FakeMessageBus();
        $writer = new InMemoryAuditLogWriter();
        $actor = ActorContext::system();
        $auditLogger = $this->makeLogger($bus, $writer, new RecordingLogger(), $actor);

        $resourceId = Uuid::generate();
        $auditLogger->log('BANK_ACCOUNTS_VIEWED', AuditLevel::ACTIVITY, AuditResource::of('Bank', $resourceId));

        $this->assertSame([], $writer->written, 'activity must not write synchronously');
        $this->assertCount(1, $bus->dispatchedMessages);

        $message = $bus->dispatchedMessages[0];
        $this->assertInstanceOf(RecordAuditEntry::class, $message);

        $entry = $message->entry;
        $this->assertSame('BANK_ACCOUNTS_VIEWED', $entry->action);
        $this->assertSame(AuditLevel::ACTIVITY, $entry->level);
        $this->assertSame('Bank', $entry->resourceType);
        $this->assertSame($resourceId, $entry->resourceId);
        $this->assertSame($actor, $entry->actor, 'the actor is sealed in the request path, not in the worker');
        $this->assertSame(self::CORRELATION_ID, $entry->correlationId);
        $this->assertSame(self::OCCURRED_ON, $entry->occurredOn->format('Y-m-d\TH:i:s.uP'));
    }

    public function testSecurityWritesSynchronouslyWithoutDispatching(): void
    {
        $bus = new FakeMessageBus();
        $writer = new InMemoryAuditLogWriter();
        $auditLogger = $this->makeLogger($bus, $writer, new RecordingLogger(), ActorContext::anonymous());

        $auditLogger->log('ACCESS_DENIED', AuditLevel::SECURITY, AuditResource::of('Bank', Uuid::generate()));

        $this->assertCount(1, $writer->written, 'security writes before send');
        $this->assertSame([], $bus->dispatchedMessages, 'security does not enqueue');
    }

    public function testAnActivityFailureIsSwallowedAndLoggedWithoutLeakingContext(): void
    {
        $failure = new RuntimeException('audit transport unavailable');
        $bus = new FakeMessageBus($failure);
        $writer = new InMemoryAuditLogWriter();
        $recordingLogger = new RecordingLogger();
        $auditLogger = $this->makeLogger($bus, $writer, $recordingLogger, ActorContext::anonymous());

        $auditLogger->log('BANK_ACCOUNTS_VIEWED', AuditLevel::ACTIVITY, AuditResource::of('Bank', 'secret-id'), ['pii' => 'x']);

        $this->assertCount(1, $recordingLogger->records, 'the gap is recorded, never silent');
        $record = $recordingLogger->records[0];
        $this->assertSame('warning', $record['level']);
        $this->assertSame(
            ['action' => 'BANK_ACCOUNTS_VIEWED', 'level' => 'activity', 'exception' => $failure],
            $record['context'],
            'the warning carries only safe keys — never metadata, actor id, or resource id',
        );
    }

    public function testASecurityFailurePropagatesAndIsNotSwallowed(): void
    {
        $bus = new FakeMessageBus();
        $writer = new InMemoryAuditLogWriter(new RuntimeException('audit_log unavailable'));
        $recordingLogger = new RecordingLogger();
        $auditLogger = $this->makeLogger($bus, $writer, $recordingLogger, ActorContext::anonymous());

        try {
            $auditLogger->log('ACCESS_DENIED', AuditLevel::SECURITY, AuditResource::of('Bank', Uuid::generate()));
            $this->fail('the security branch must propagate the write failure');
        } catch (RuntimeException) {
            // expected: a write-before-send failure is never swallowed
        }

        $this->assertSame([], $recordingLogger->records, 'a security failure propagates; it is not downgraded to a warning');
    }

    private function makeLogger(
        FakeMessageBus $bus,
        InMemoryAuditLogWriter $writer,
        RecordingLogger $logger,
        ActorContext $actor,
    ): SymfonyAuditLogger {
        $request = new Request();
        $request->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY, self::CORRELATION_ID);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new SymfonyAuditLogger(
            $bus,
            $writer,
            new FixedActorContextFactory($actor),
            new FixedClock(new DateTimeImmutable(self::OCCURRED_ON)),
            $requestStack,
            $logger,
        );
    }
}
