<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

use Erpify\Shared\Audit\Domain\Exception\InvalidAuditLogEntry;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * The optional resource an audited action targets, binding the (type, id) pair that {@see AuditLogEntry}
 * keeps in two columns into one value. Closed behind {@see of()} so "type without id" / "id without type"
 * cannot exist, and so a call site reads `of('Bank', $bankId)` instead of two loose, order-fragile
 * parameters. The `type` is required and never blank (an untyped resource is unidentifiable); the `id`
 * must be a UUID — it keys the `audit_log.resource_id` (`uuid`) column, so a non-UUID id is rejected here
 * as a clean domain fault rather than surfacing later as a raw driver `CAST` error mid-flush.
 */
final readonly class AuditResource
{
    private function __construct(
        public string $type,
        public string $id,
    ) {
    }

    public static function of(string $type, string $id): self
    {
        if ('' === \trim($type)) {
            throw InvalidAuditLogEntry::resourceTypeMustNotBeEmpty();
        }

        if (!Uuid::isValid($id)) {
            throw InvalidAuditLogEntry::resourceIdMustBeUuid();
        }

        return new self($type, $id);
    }
}
