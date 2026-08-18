<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure;

use DateTimeImmutable;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Infrastructure\SealedAuditEntryFactory;
use Erpify\Shared\Http\Infrastructure\CorrelationIdListener;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedActorContextFactory;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @internal
 */
#[CoversClass(SealedAuditEntryFactory::class)]
final class SealedAuditEntryFactoryTest extends TestCase
{
    private const string CORRELATION_ID = '019877c2-1f3a-7b8c-8d2e-1a2b3c4d5e6f';

    private const string OCCURRED_ON = '2026-06-23T12:34:56.123456+00:00';

    public function testItSealsActorClockResourceAndAdoptsTheRequestCorrelationId(): void
    {
        $actor = ActorContext::system();
        $resourceId = Uuid::generate();

        $entry = $this->factoryForRequest($actor, self::CORRELATION_ID)->create(
            'BANK_ACCOUNTS_VIEWED',
            AuditLevel::ACTIVITY,
            AuditResource::of('Bank', $resourceId),
            ['page' => 2],
        );

        $this->assertSame('BANK_ACCOUNTS_VIEWED', $entry->action);
        $this->assertSame(AuditLevel::ACTIVITY, $entry->level);
        $this->assertSame($actor, $entry->actor, 'the actor is sealed from the factory, not re-resolved later');
        $this->assertInstanceOf(AuditResource::class, $entry->resource);
        $this->assertSame('Bank', $entry->resource->type);
        $this->assertSame($resourceId, $entry->resource->id);
        $this->assertSame(['page' => 2], $entry->metadata);
        $this->assertSame(self::CORRELATION_ID, $entry->correlationId, 'the request canonical id is adopted');
        $this->assertSame(self::OCCURRED_ON, $entry->occurredOn->format('Y-m-d\TH:i:s.uP'));
    }

    public function testItSealsTheClientIpAndUserAgentFromTheRequest(): void
    {
        $request = new Request(server: ['REMOTE_ADDR' => '203.0.113.7']);
        $request->headers->set('User-Agent', 'Mozilla/5.0 (probe)');

        $requestStack = new RequestStack([$request]);

        $entry = $this->factory($requestStack)->create('ROUTE_BACKOFFICE_BANK_SEARCH', AuditLevel::ACTIVITY);

        $this->assertSame('203.0.113.7', $entry->ip);
        $this->assertSame('Mozilla/5.0 (probe)', $entry->userAgent);
    }

    public function testItLeavesTheClientIpAndUserAgentNullOffRequest(): void
    {
        $entry = $this->factory(new RequestStack())->create('SYSTEM_TICK', AuditLevel::ACTIVITY);

        $this->assertNull($entry->ip);
        $this->assertNull($entry->userAgent);
    }

    public function testItTrimsAnOverLongUserAgentToTheColumnWidth(): void
    {
        $request = new Request();
        $request->headers->set('User-Agent', \str_repeat('a', 600));

        $requestStack = new RequestStack([$request]);

        $entry = $this->factory($requestStack)->create('ROUTE_BACKOFFICE_BANK_SEARCH', AuditLevel::ACTIVITY);

        $this->assertSame(\str_repeat('a', 512), $entry->userAgent);
    }

    public function testItMintsACanonicalFallbackWhenNoRequestIsInFlight(): void
    {
        $entry = $this->factory(new RequestStack())->create('SYSTEM_TICK', AuditLevel::ACTIVITY);

        $this->assertTrue(
            CorrelationIdListener::isCanonical($entry->correlationId),
            'off-request a fresh canonical correlation id is minted, never null',
        );
    }

    public function testItMintsACanonicalFallbackWhenTheRequestCarriesNoCorrelationId(): void
    {
        $requestStack = new RequestStack([new Request()]);

        $entry = $this->factory($requestStack)->create('BANK_ACCOUNTS_VIEWED', AuditLevel::ACTIVITY);

        $this->assertTrue(CorrelationIdListener::isCanonical($entry->correlationId));
        $this->assertNotSame(self::CORRELATION_ID, $entry->correlationId);
    }

    private function factoryForRequest(ActorContext $actor, string $correlationId): SealedAuditEntryFactory
    {
        $request = new Request();
        $request->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY, $correlationId);

        $requestStack = new RequestStack([$request]);

        return $this->factory($requestStack, $actor);
    }

    private function factory(RequestStack $requestStack, ?ActorContext $actor = null): SealedAuditEntryFactory
    {
        return new SealedAuditEntryFactory(
            new FixedActorContextFactory($actor ?? ActorContext::system()),
            new FixedClock(new DateTimeImmutable(self::OCCURRED_ON)),
            $requestStack,
        );
    }
}
