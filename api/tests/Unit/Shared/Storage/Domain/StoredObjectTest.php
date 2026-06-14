<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Storage\Domain;

use Erpify\Shared\Storage\Domain\StoredObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(StoredObject::class)]
final class StoredObjectTest extends TestCase
{
    public function testAnAllNullValueObjectIsEmpty(): void
    {
        $this->assertTrue((new StoredObject())->isEmpty());
    }

    public function testAValueObjectWithAKeyIsNotEmpty(): void
    {
        $storedObject = new StoredObject('object/key', 'image/webp', 1024, 'abc123');

        $this->assertFalse($storedObject->isEmpty());
        $this->assertSame('object/key', $storedObject->objectKey);
        $this->assertSame('image/webp', $storedObject->mimeType);
        $this->assertSame(1024, $storedObject->byteSize);
        $this->assertSame('abc123', $storedObject->contentHash);
    }
}
