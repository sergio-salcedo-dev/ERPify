<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Domain;

use DateTimeImmutable;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditProvenance;
use Erpify\Shared\Audit\Domain\AuditRedaction;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuditProvenance::class)]
#[CoversClass(AuditRedaction::class)]
final class AuditProvenanceTest extends TestCase
{
    /**
     * The rule itself is pinned case by case in AuditRedactionTest; this proves the value every `audit_log`
     * row is sealed with applies it to both captured fields.
     */
    public function testNeutralisesACapturedIpAndUserAgentEqualToTheRedactionSentinel(): void
    {
        $provenance = new AuditProvenance(
            ActorContext::anonymous(),
            Uuid::generate(),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            AuditRedaction::SENTINEL,
            '  [redacted] ',
        );

        $this->assertSame('[client-supplied] [REDACTED]', $provenance->ip);
        $this->assertSame('[client-supplied] [redacted]', $provenance->userAgent);
    }

    public function testKeepsAnOrdinaryCaptureAndAnAbsentOneAsGiven(): void
    {
        $captured = new AuditProvenance(
            ActorContext::anonymous(),
            Uuid::generate(),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            '203.0.113.7',
            'Mozilla/5.0',
        );
        $absent = new AuditProvenance(
            ActorContext::system(),
            Uuid::generate(),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        $this->assertSame('203.0.113.7', $captured->ip);
        $this->assertSame('Mozilla/5.0', $captured->userAgent);
        $this->assertNull($absent->ip);
        $this->assertNull($absent->userAgent);
    }
}
