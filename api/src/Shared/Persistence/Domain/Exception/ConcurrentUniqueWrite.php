<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when the database, not the application, refused a write for uniqueness.
 *
 * `#[UniqueEntity]` is a SELECT and the write is an INSERT, and nothing makes the pair atomic: between
 * them a competing request can commit the same value. The application's own check passes, the write
 * then hits the unique index, and Postgres decides.
 *
 * **Why 409 and not the 422 the check would have produced.** Not because the field cannot be known —
 * the driver names the constraint, and `bank_account` has exactly one unique index anyway. Because a
 * `DomainException` cannot carry `violations`: the key is reserved and stripped from the body, so a
 * 422 without them would be a worse 422. Answering the status that describes what happened beats
 * fabricating the shape of a check that never ran.
 *
 * The driver's own message is deliberately not carried, not even as `previous` — the shape both
 * siblings in this namespace use on purpose. It names the offending value
 * (`DETAIL: Key (iban)=(…) already exists.`) and `bank_account.iban` is `#[PersonalData]`. The cost is
 * named rather than hidden: when a bank loses the race, nothing records which of its two unique
 * columns fired.
 */
final class ConcurrentUniqueWrite extends DomainException implements Conflict
{
    public static function onWrite(string $resource): self
    {
        return new self(
            type: 'concurrent-unique-write',
            title: 'That value was taken by a concurrent request.',
            context: ['resource' => $resource],
        );
    }
}
