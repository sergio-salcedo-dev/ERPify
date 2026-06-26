<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Audit\Application;

use DateTimeImmutable;
use Erpify\Backoffice\Audit\Application\AuditTimelineSearcher;
use Erpify\Backoffice\Audit\Application\Query\SearchAuditTimelineQuery;
use Erpify\Backoffice\Audit\Domain\AuditTimelineEntry;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuditTimelineSearcher::class)]
#[CoversClass(SearchAuditTimelineQuery::class)]
final class AuditTimelineSearcherTest extends TestCase
{
    public function testReturnsThePortPageVerbatimForwardingTheQueryCriteria(): void
    {
        $page = $this->pageOf($this->entry('ROUTE_ONE'), $this->entry('ROUTE_TWO'));
        $repository = new InMemoryAuditTimelineRepository($page);
        $criteria = new SearchCriteria(limit: 10);

        $result = (new AuditTimelineSearcher($repository))->search(new SearchAuditTimelineQuery($criteria));

        // Thin read-side seam: the handler returns the port's page untouched and passes the criteria
        // through unwrapped — no enrichment, no re-paging.
        $this->assertSame($page, $result);
        $this->assertSame($criteria, $repository->receivedCriteria);
    }

    public function testEmptyPageIsForwardedWithoutFailing(): void
    {
        $repository = new InMemoryAuditTimelineRepository($this->pageOf());

        $result = (new AuditTimelineSearcher($repository))->search(
            new SearchAuditTimelineQuery(new SearchCriteria()),
        );

        $this->assertSame([], $result->items);
    }

    /**
     * @return Page<AuditTimelineEntry>
     */
    private function pageOf(AuditTimelineEntry ...$entries): Page
    {
        return new Page(items: \array_values($entries), hasNext: false, hasPrev: false);
    }

    private function entry(string $action): AuditTimelineEntry
    {
        return new AuditTimelineEntry(
            '0190abcd-1234-7abc-8def-001122334455',
            new DateTimeImmutable('2026-03-02T10:11:12.123456+00:00'),
            'activity',
            $action,
            'anonymous',
            null,
            '0190abcd-1234-7abc-8def-001122330000',
            null,
            null,
        );
    }
}
