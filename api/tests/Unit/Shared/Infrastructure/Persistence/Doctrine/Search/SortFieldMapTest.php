<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Persistence\Doctrine\Search;

use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\SortFieldMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SortFieldMap::class)]
final class SortFieldMapTest extends TestCase
{
    public function testResolvesTheDqlPathForAMappedPublicField(): void
    {
        $map = new SortFieldMap(['name' => 'b.nameNormalized', 'createdAt' => 'b.createdAt']);

        $this->assertSame('b.nameNormalized', $map->pathFor('name'));
        $this->assertSame('b.createdAt', $map->pathFor('createdAt'));
    }

    public function testReturnsNullForAFieldOutsideTheAllowList(): void
    {
        $map = new SortFieldMap(['name' => 'b.nameNormalized']);

        $this->assertNull($map->pathFor('id'));
    }

    public function testAnEmptyMapExposesNothing(): void
    {
        $this->assertNull((new SortFieldMap([]))->pathFor('name'));
    }
}
