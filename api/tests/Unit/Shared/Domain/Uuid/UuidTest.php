<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Uuid;

use Erpify\Shared\Domain\Uuid\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Uuid::class)]
final class UuidTest extends TestCase
{
    private const string V7_PATTERN
        = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testGeneratesUniqueLowercaseV7Uuids(): void
    {
        $generated = [];

        for ($i = 0; $i < 1000; ++$i) {
            $uuid = Uuid::generate();
            $this->assertMatchesRegularExpression(self::V7_PATTERN, $uuid);
            $generated[] = $uuid;
        }

        $this->assertCount(1000, \array_unique($generated), 'every generated UUID must be unique');
    }
}
