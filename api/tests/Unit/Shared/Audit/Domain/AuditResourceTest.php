<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Domain;

use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuditResource::class)]
final class AuditResourceTest extends TestCase
{
    public function testOfBindsTypeAndId(): void
    {
        $id = Uuid::generate();

        $resource = AuditResource::of('Bank', $id);

        $this->assertSame('Bank', $resource->type);
        $this->assertSame($id, $resource->id);
    }
}
