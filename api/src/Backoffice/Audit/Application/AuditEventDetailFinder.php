<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Application;

use Erpify\Backoffice\Audit\Domain\AuditEventDetail;
use Erpify\Backoffice\Audit\Domain\Exception\AuditEventNotFound;
use Erpify\Backoffice\Audit\Domain\Repository\AuditEventDetailRepository;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Read-side handler for the single audit-event detail. Mirrors
 * {@see \Erpify\Backoffice\Bank\Application\BankFinder}: the malformed id is a request-target syntax
 * error surfaced as 400 `invalid-uuid` BEFORE any lookup, distinct from a well-formed id with no
 * matching row, which is 404 — two outcomes the wire contract keeps apart. The use-case boundary is
 * also where any future read policy (RBAC gating, masking) would land.
 */
final readonly class AuditEventDetailFinder
{
    public function __construct(
        private AuditEventDetailRepository $auditEventDetailRepository,
    ) {
    }

    /**
     * @throws InvalidUuidException when $id is not a well-formed RFC 4122 UUID
     * @throws AuditEventNotFound   when no audit event exists for the given $id
     */
    public function find(string $id): AuditEventDetail
    {
        Uuid::ensure($id);

        $detail = $this->auditEventDetailRepository->findById($id);

        if (!$detail instanceof AuditEventDetail) {
            throw AuditEventNotFound::withId($id);
        }

        return $detail;
    }
}
