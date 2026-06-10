<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Search;

use Erpify\Shared\Domain\Search\Page;
use Erpify\Tests\Unit\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\Mother\PageMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Page::class)]
final class PageTest extends TestCase
{
    #[Test]
    public function itExposesItsItemsAndNavigationMetadata(): void
    {
        $page = PageMother::create(
            items: ['BBVA', 'Caixa'],
            hasNext: true,
            hasPrev: false,
            count: 42,
            nextCursor: 'next-token',
        );

        $this->assertSame(['BBVA', 'Caixa'], $page->items);
        $this->assertTrue($page->hasNext);
        $this->assertFalse($page->hasPrev);
        $this->assertSame(42, $page->count);
        $this->assertSame('next-token', $page->nextCursor);
        $this->assertNull($page->prevCursor);
        $this->assertFalse($page->isEmpty());
    }

    #[Test]
    public function itBuildsAnEmptyPage(): void
    {
        $page = Page::empty();

        $this->assertSame([], $page->items);
        $this->assertFalse($page->hasNext);
        $this->assertFalse($page->hasPrev);
        $this->assertNull($page->count);
        $this->assertNull($page->nextCursor);
        $this->assertNull($page->prevCursor);
        $this->assertTrue($page->isEmpty());
    }
}
