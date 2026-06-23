<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

/**
 * The optional resource an audited action targets, binding the (type, id) pair that {@see AuditLogEntry}
 * keeps in two columns into one value. Closed behind {@see of()} so "type without id" / "id without type"
 * cannot exist, and so a call site reads `of('Bank', $bankId)` instead of two loose, order-fragile
 * parameters. The id is an upstream-validated aggregate id, carried as a string and not re-validated here.
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
        return new self($type, $id);
    }
}
