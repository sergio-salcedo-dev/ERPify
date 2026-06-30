<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

/**
 * The kind of write change-data-capture observed. The backing value is the action suffix the capture
 * stamps onto the semantic action — an audited write of a `Bank` yields `BANK_CREATED` / `BANK_UPDATED` /
 * `BANK_DELETED` — so the closed set of operations and the action vocabulary stay in one place.
 */
enum AuditWriteOperation: string
{
    case CREATED = 'CREATED';
    case UPDATED = 'UPDATED';
    case DELETED = 'DELETED';
}
