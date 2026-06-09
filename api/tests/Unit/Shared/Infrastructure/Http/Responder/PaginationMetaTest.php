<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Http\Responder;

use Erpify\Shared\Domain\Search\PaginatedResult;
use Erpify\Shared\Infrastructure\Http\Responder\PaginationMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PaginationMeta::class)]
final class PaginationMetaTest extends TestCase
{
    public function testToArrayEmitsTheExactWireShape(): void
    {
        $meta = new PaginationMeta(
            currentPage: 2,
            pageCount: 7,
            count: 31,
            hasMorePages: true,
            cursor: 'encoded-cursor',
        );

        $this->assertSame([
            'currentPage' => 2,
            'pageCount' => 7,
            'count' => 31,
            'hasMorePages' => true,
            'cursor' => 'encoded-cursor',
        ], $meta->toArray());
    }

    public function testFromPaginatedResultReadsMetadataFromTheResultAndTakesTheEncodedCursor(): void
    {
        $result = $this->createStub(PaginatedResult::class);
        $result->method('getCurrentPage')->willReturn(2);
        $result->method('getPageCount')->willReturn(7);
        $result->method('getCount')->willReturn(31);
        $result->method('hasMorePages')->willReturn(true);

        $meta = PaginationMeta::fromPaginatedResult($result, 'encoded-cursor');

        $this->assertSame([
            'currentPage' => 2,
            'pageCount' => 7,
            'count' => 31,
            'hasMorePages' => true,
            'cursor' => 'encoded-cursor',
        ], $meta->toArray());
    }

    public function testLightPaginationModeLeavesPageCountAndCountNull(): void
    {
        $result = $this->createStub(PaginatedResult::class);
        $result->method('getCurrentPage')->willReturn(1);
        $result->method('getPageCount')->willReturn(null);
        $result->method('getCount')->willReturn(null);
        $result->method('hasMorePages')->willReturn(true);

        $meta = PaginationMeta::fromPaginatedResult($result, 'encoded-cursor');

        $this->assertNull($meta->pageCount);
        $this->assertNull($meta->count);
        $this->assertSame(1, $meta->currentPage);
        $this->assertTrue($meta->hasMorePages);
    }
}
