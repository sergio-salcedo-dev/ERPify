<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Storage;

/**
 * The closed set of storage operations the observability signal reports under. Kept an enum rather than
 * a string at each call site so the metric dimension cannot acquire a free-form value, which on a metric
 * is a cardinality explosion rather than a typo.
 */
enum StorageOperation: string
{
    case Store = 'store';
    case Read = 'read';
    case Delete = 'delete';
    case VerifyIntegrity = 'verify_integrity';
}
