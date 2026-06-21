<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Domain\Exception;

/**
 * The internal, distinguishable cause carried by an {@see InvalidCursor}.
 *
 * The wire response is identical for every cause (shared `invalid-cursor` type,
 * shared title) — see {@see InvalidCursor}. The cause never reaches the client;
 * it travels only for logging and the `invalid_cursor_count{cause}` metric the
 * cursor-validation layer emits in PR3. The backing strings ARE that metric's
 * label set and the cursor-validation DAG order — they are fixed and must not
 * be renamed or extended without a contract change.
 */
enum InvalidCursorCause: string
{
    case Signature = 'signature';

    case Version = 'version';

    case Payload = 'payload';

    case Fingerprint = 'fingerprint';
}
