<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Audit\Application;

use Erpify\Backoffice\Audit\Domain\AuditTimelineEntry;
use Erpify\Backoffice\Audit\Domain\Repository\AuditTimelineRepository;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Override;

/**
 * In-memory {@see AuditTimelineRepository} returning a prebuilt {@see Page} and recording the
 * {@see SearchCriteria} it was handed, so a test can drive the read-side handler in
 * {@see \Erpify\Backoffice\Audit\Application\AuditTimelineSearcher} without a database.
 *
 * @internal
 */
final class InMemoryAuditTimelineRepository implements AuditTimelineRepository
{
    public ?SearchCriteria $receivedCriteria = null;

    /**
     * @param Page<AuditTimelineEntry> $page
     */
    public function __construct(private readonly Page $page)
    {
    }

    /**
     * @return Page<AuditTimelineEntry>
     */
    #[Override]
    public function search(SearchCriteria $criteria): Page
    {
        $this->receivedCriteria = $criteria;

        return $this->page;
    }
}
