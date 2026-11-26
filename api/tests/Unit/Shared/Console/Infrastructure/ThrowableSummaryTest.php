<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Console\Infrastructure;

use Erpify\Shared\Console\Infrastructure\ThrowableSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(ThrowableSummary::class)]
final class ThrowableSummaryTest extends TestCase
{
    #[Test]
    #[DataProvider('provideItMasksIdentifiersAndAddressesAndKeepsTheRestCases')]
    public function itMasksIdentifiersAndAddressesAndKeepsTheRest(string $message, string $expected): void
    {
        $this->assertSame($expected, ThrowableSummary::of(new RuntimeException($message)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideItMasksIdentifiersAndAddressesAndKeepsTheRestCases(): iterable
    {
        yield 'a uuid in a driver detail' => [
            'SQLSTATE[23503]: DETAIL: Key (actor_id)=(0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c) is not present',
            'SQLSTATE[23503]: DETAIL: Key (actor_id)=(<id>) is not present',
        ];
        yield 'an upper-case uuid' => ['id 0190A1B2-C3D4-7E5F-8A9B-0C1D2E3F4A5C failed', 'id <id> failed'];
        yield 'an address' => ['could not notify jane.doe@example.test now', 'could not notify <address> now'];
        yield 'both, twice' => [
            'a@b.test and 0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c and c@d.test',
            '<address> and <id> and <address>',
        ];
        yield 'nothing to hide' => ['connection refused', 'connection refused'];
    }

    #[Test]
    public function itFallsBackToTheClassWhenTheMessageCannotBeRead(): void
    {
        // Invalid UTF-8 makes no difference to a byte pattern, so the message is still summarised.
        $summary = ThrowableSummary::of(new RuntimeException("bad \xB1 bytes 0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c"));

        $this->assertStringContainsString('<id>', $summary);
        $this->assertStringNotContainsString('0190a1b2', $summary);
    }
}
