<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Serialization;

use Erpify\Shared\Event\Infrastructure\Serialization\NullUpcaster;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(NullUpcaster::class)]
final class NullUpcasterTest extends TestCase
{
    #[Test]
    public function itReturnsThePayloadUntouched(): void
    {
        $payload = ['name' => 'Acme', 'shortName' => 'ACME'];

        $this->assertSame($payload, (new NullUpcaster())->upcast('erpify.backoffice.bank.created', 1, $payload));
    }

    #[Test]
    public function theTargetVersionIsTheSourceVersion(): void
    {
        $this->assertSame(3, (new NullUpcaster())->targetVersion('erpify.backoffice.bank.created', 3));
    }
}
