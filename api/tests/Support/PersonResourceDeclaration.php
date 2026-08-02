<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * What a `person` line of `api/.audit-resource-types` declares: who erases the type, and what proves that
 * the erasure reaches a row of it.
 *
 * The two paths are separate fields rather than one list because they answer different questions and are
 * checked by different rules — the owner is read as PHP source for its wiring, the witness as an acceptance
 * scenario for its write-then-vanish pair — and because a positional list would let the two be swapped with
 * every check still passing something.
 *
 * @internal test support
 */
final readonly class PersonResourceDeclaration
{
    public function __construct(
        public string $erasedBy,
        public string $witness,
    ) {
    }
}
