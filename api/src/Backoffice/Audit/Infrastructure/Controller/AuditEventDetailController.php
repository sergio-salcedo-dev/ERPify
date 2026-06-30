<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Infrastructure\Controller;

use Erpify\Backoffice\Audit\Application\AuditEventDetailFinder;
use Erpify\Backoffice\Audit\Infrastructure\Http\AuditEventDetailResourceMapper;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /api/v1/backoffice/audit/events/{id}` — the canonical audit event, returning the full row with
 * its field-by-field diff (`metadata.changes`). An audit event is a resource in its own right, not the
 * detail of the timeline listing, which stays slim; this resolves deep-links and keeps the JSONB diff
 * off the hot paginated path. The finder guards `{id}` with `Uuid::ensure` (400 `invalid-uuid`) before
 * any lookup and maps an absent row to 404 through the RFC 9457 pipeline.
 *
 * Conscious public route: the codebase has no security firewall/voter yet (the timeline and bank read
 * routes are public too), so the RBAC gate is a pre-prod follow-up (ADR D8/E3), not this slice. No
 * `_audit_resource_type` default is declared on purpose — auditing the read of the audit log would be
 * recursive noise; self-auditing is the same D8/E3 work.
 */
#[Route('/audit/events/{id}', name: 'backoffice_audit_event_detail', methods: ['GET'])]
final readonly class AuditEventDetailController
{
    public function __construct(
        private AuditEventDetailFinder $auditEventDetailFinder,
        private AuditEventDetailResourceMapper $auditEventDetailResourceMapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    public function __invoke(string $id): Response
    {
        return $this->resourceResponder->respond(
            $this->auditEventDetailResourceMapper->toResource(
                $this->auditEventDetailFinder->find($id),
            ),
        );
    }
}
