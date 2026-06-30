<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

/**
 * The closed set of write kinds the change-data-capture distinguishes (insert / update / delete). It is the
 * operation the capture listener hands to the audited aggregate so the aggregate can name the change: the
 * semantic action vocabulary it maps this to (`BANK_CREATED` / `BANK_UPDATED` / `BANK_DELETED`) is owned by
 * the module via {@see AuditedEntity::auditAction()}, not assembled here.
 */
enum AuditWriteOperation: string
{
    case CREATED = 'CREATED';
    case UPDATED = 'UPDATED';
    case DELETED = 'DELETED';
}
