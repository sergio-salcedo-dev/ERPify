<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Domain;

/**
 * Domain value object for the keyset navigation intent: the single routing
 * authority derived 1:1 from which opaque wire cursor arrived (`after` vs
 * `before`). It is the ONLY source of truth for navigation direction (AR21);
 * the cursor payload's own `dir` is an integrity binding, never a fallback.
 *
 * Lives in `Domain/` (no framework, no infrastructure import) so every adapter
 * — HTTP DTO, message handlers — speaks one direction type. The backing string
 * values intentionally mirror the infrastructure wire tokens
 * ({@see \Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\Cursor}'s
 * `DIRECTION_AFTER`/`DIRECTION_BEFORE}); a contract test pins that coherence so
 * the mapping can never drift silently.
 */
enum NavigationDirection: string
{
    case After = 'after';

    case Before = 'before';
}
