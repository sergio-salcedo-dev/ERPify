<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Domain;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when the database, not the application, refused a write for uniqueness.
 *
 * `#[UniqueEntity]` is a SELECT and the write is an INSERT, and nothing makes the pair atomic: between
 * them a competing request can commit the same value. The application's own check passes, the write
 * then hits the unique index, and Postgres decides.
 *
 * It is a 409 rather than the 422 the check would have produced, and that is a deliberate limit on
 * what the server claims to know. Reconstructing the exact field violation would mean asking the
 * database which value collided — and by then there is nothing to ask with: a failed commit closes the
 * EntityManager (`UnitOfWork::commit()` calls `close()` in its `finally`), so the `UniqueEntity`
 * re-check cannot run. 409 says what actually happened. A retry gets the precise 422, because by then
 * the competing row is committed and the SELECT sees it.
 *
 * The driver's own message is deliberately not carried: it names the offending value
 * (`DETAIL: Key (iban)=(…) already exists.`), that value can be personal data, and an exception
 * message is rendered into the error log and the Sentry event.
 */
final class ConcurrentUniqueWrite extends DomainException implements Conflict
{
    public static function onWrite(string $resource): self
    {
        return new self(
            type: 'concurrent-unique-write',
            title: 'The write conflicted with a concurrent one. Retry the request.',
            context: ['resource' => $resource],
        );
    }
}
