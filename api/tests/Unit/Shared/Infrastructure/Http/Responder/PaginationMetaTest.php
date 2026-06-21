<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Http\Responder;

use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Infrastructure\Http\Responder\PaginationMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PaginationMeta::class)]
final class PaginationMetaTest extends TestCase
{
    public function testToArrayEmitsTheConstantCursorOnlyShape(): void
    {
        $meta = new PaginationMeta(
            hasNext: true,
            hasPrev: false,
            count: 31,
            nextLink: '/api/v1/backoffice/banks?after=abc',
            prevLink: null,
        );

        $this->assertSame([
            'hasNext' => true,
            'hasPrev' => false,
            'count' => 31,
            'links' => [
                'next' => '/api/v1/backoffice/banks?after=abc',
                'prev' => null,
            ],
        ], $meta->toArray());
    }

    public function testFromPageCopiesFlagsAndCountAndTakesThePrebuiltLinks(): void
    {
        $page = new Page(items: [], hasNext: true, hasPrev: true, count: 12, nextCursor: 'n', prevCursor: 'p');

        $meta = PaginationMeta::fromPage($page, '/banks?after=n', '/banks?before=p');

        $this->assertSame([
            'hasNext' => true,
            'hasPrev' => true,
            'count' => 12,
            'links' => ['next' => '/banks?after=n', 'prev' => '/banks?before=p'],
        ], $meta->toArray());
    }

    public function testNullsAreEmittedExplicitlyNeverSkipped(): void
    {
        // Light pagination (count null) on a single full page (no next/prev): every key is still
        // present with an explicit null — `skip_null_values` is forbidden (W1/AR20). assertSame on
        // the WHOLE array pins both key presence and the explicit-null values.
        $page = new Page(items: [], hasNext: false, hasPrev: false);

        $this->assertSame([
            'hasNext' => false,
            'hasPrev' => false,
            'count' => null,
            'links' => ['next' => null, 'prev' => null],
        ], PaginationMeta::fromPage($page, null, null)->toArray());
    }
}
