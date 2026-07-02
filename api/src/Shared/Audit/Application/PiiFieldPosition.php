<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

/**
 * Which side of a field-level change a sealed value is — the `old` or the `new` of a diff row. Bound into
 * the audit additional-authenticated-data ({@see AuditPiiAad}) so a ciphertext cannot be moved between the
 * two sides of a change without failing authentication. The backing value matches the diff's `old`/`new`
 * keys so the AAD reads exactly as the metadata it protects.
 */
enum PiiFieldPosition: string
{
    case Old = 'old';
    case New = 'new';
}
