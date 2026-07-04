<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Infrastructure\Controller;

use Erpify\Backoffice\Audit\Application\AuditTimelineSearcher;
use Erpify\Backoffice\Audit\Application\Query\SearchAuditTimelineQuery;
use Erpify\Backoffice\Audit\Infrastructure\Http\AuditTimelineResourceMapper;
use Erpify\Shared\Search\Application\Http\SearchQuery;
use Erpify\Shared\Search\Infrastructure\Http\SearchResponder;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * `GET /api/v1/backoffice/audit/timeline` — the audit investigation read model: a keyset-paginated,
 * filterable timeline over `audit_log`.
 */
#[Route('/audit/timeline', name: self::ROUTE_NAME, methods: ['GET'])]
#[IsGranted('ROLE_AUDIT_READER')]
final readonly class AuditTimelineSearchController
{
    public const string ROUTE_NAME = 'backoffice_audit_timeline_search';

    public function __construct(
        private AuditTimelineSearcher $auditTimelineSearcher,
        private AuditTimelineResourceMapper $auditTimelineResourceMapper,
        private SearchResponder $searchResponder,
    ) {
    }

    /**
     * @throws ExceptionInterface
     * @throws JsonException
     */
    public function __invoke(
        #[MapQueryString]
        SearchQuery $query = new SearchQuery(),
    ): Response {
        return $this->searchResponder->respond(
            $this->auditTimelineResourceMapper->toListPage(
                $this->auditTimelineSearcher->search(new SearchAuditTimelineQuery($query->toCriteria())),
            ),
            $query,
            self::ROUTE_NAME,
        );
    }
}
