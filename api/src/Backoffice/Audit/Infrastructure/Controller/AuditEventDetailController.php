<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Infrastructure\Controller;

use Erpify\Backoffice\Audit\Application\AuditEventDetailFinder;
use Erpify\Backoffice\Audit\Infrastructure\Http\AuditEventDetailResourceMapper;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `GET /api/v1/backoffice/audit/events/{id}` — the canonical audit event, returning the full row with
 * its field-by-field diff (`metadata.changes`). An audit event is a resource in its own right, not the
 * detail of the timeline listing, which stays slim; this resolves deep-links and keeps the JSONB diff
 * off the hot paginated path. The finder guards `{id}` with `Uuid::ensure` (400 `invalid-uuid`) before
 * any lookup and maps an absent row to 404 through the RFC 9457 pipeline.
 *
 * No `_audit_resource_type` default is declared on purpose: the access-log hook would otherwise record
 * every read of the audit log as an audit entry, which is recursive noise. `_audit_canonical` makes that
 * generic hook yield, and
 * {@see \Erpify\Backoffice\Audit\Infrastructure\Http\EventListener\AuditTrailReadAuditListener} records
 * each authorized read as a `security` `AUDIT_TRAIL_READ` entry carrying the read event id in metadata
 * (never as a linked resource, which would recurse).
 */
#[Route('/audit/events/{id}', name: self::ROUTE_NAME, defaults: ['_audit_canonical' => true], methods: ['GET'])]
#[IsGranted('ROLE_AUDIT_READER')]
final readonly class AuditEventDetailController
{
    public const string ROUTE_NAME = 'backoffice_audit_event_detail';

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
