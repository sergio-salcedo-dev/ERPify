<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Domain;

use Erpify\Shared\Audit\Domain\AuditRedaction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuditRedaction::class)]
final class AuditRedactionTest extends TestCase
{
    /**
     * Mirrors the `VARCHAR(512)` width of `audit_log.user_agent`, the width
     * `SealedAuditEntryFactory` truncates a captured header to.
     */
    private const int COLUMN_WIDTH = 512;

    #[DataProvider('provideNeutraliseCapturedCases')]
    public function testNeutraliseCaptured(?string $captured, ?string $expected): void
    {
        $neutralised = AuditRedaction::neutraliseCaptured($captured);

        $this->assertSame($expected, $neutralised);
        $this->assertNotSame(AuditRedaction::SENTINEL, $neutralised);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function provideNeutraliseCapturedCases(): iterable
    {
        yield 'an ordinary value passes untouched' => ['Mozilla/5.0', 'Mozilla/5.0'];
        yield 'the exact sentinel is prefixed' => ['[REDACTED]', '[client-supplied] [REDACTED]'];
        yield 'a padded, lower-cased variant is trimmed then prefixed' => [
            \str_pad('  [redacted] ', self::COLUMN_WIDTH, ' '),
            '[client-supplied] [redacted]',
        ];
        yield 'a value merely containing the sentinel passes untouched' => ['foo [REDACTED]', 'foo [REDACTED]'];
        yield 'an absent value stays absent' => [null, null];
        yield 'an empty value stays empty' => ['', ''];
    }

    public function testANeutralisedValuePaddedToTheColumnWidthStillFitsIt(): void
    {
        $neutralised = AuditRedaction::neutraliseCaptured(
            \str_pad(AuditRedaction::SENTINEL, self::COLUMN_WIDTH, ' ', STR_PAD_BOTH),
        );

        $this->assertIsString($neutralised);
        $this->assertStringStartsWith(AuditRedaction::CLIENT_SUPPLIED_PREFIX, $neutralised);
        $this->assertLessThanOrEqual(self::COLUMN_WIDTH, \mb_strlen($neutralised));
    }
}
