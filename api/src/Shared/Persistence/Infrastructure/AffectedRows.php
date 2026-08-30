<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Infrastructure;

use UnexpectedValueException;

/**
 * Narrows the result of a bulk DQL statement to its affected-row count, raising when it is not one.
 *
 * **It exists because `Doctrine\ORM\AbstractQuery::execute()` is declared `mixed`**, so every adapter whose
 * port promises an `int` has to narrow that result, and the narrowing each of them reached for independently
 * was `\is_int($affected) ? $affected : 0`. That fallback mints the one value its callers read as evidence:
 * a bulk delete's count flows into `IdentityErasureResult` and out through `identity:gdpr:erase-subject`,
 * while `SessionRepository::deleteAllForUser()` promises that "a second pass with a subject that has no rows
 * deletes nothing and returns 0" — so a legitimate zero and a zero minted by the type fallback are
 * indistinguishable to every caller, and the minted one asserts "there was nothing to erase" in the direction
 * that looks safe. An erasure is the operation whose evidence may least be invented.
 *
 * **Raising is what makes the two tellable apart, and it forfeits nothing that was ever real.** The branch is
 * unreachable today: a DQL `DELETE`/`UPDATE` returns the driver's `int`. What changes is the direction the
 * mistake fails in the day that stops holding — a decorated `Query`, an ORM whose hydration path moves, a
 * statement that quietly stops being a bulk one — and it then fails loudly instead of as a zero no caller can
 * question.
 *
 * `UnexpectedValueException` rather than a `DomainException`: nothing about the caller's request is wrong and
 * there is no answer a client could act on, so it belongs with the other "the store handed back a shape that
 * cannot be right" raises in this codebase (`DbalAuditTimelineRepository::requiredString()`) and surfaces as
 * the 500 it is. A marker interface would put it in the error contract as though a client had a choice.
 *
 * **What a pass here does not prove.** It never judges whether the count is CORRECT: a statement missing a
 * predicate, one whose transaction rolls back after it, or one counting the wrong table all return a
 * perfectly well-typed `int` and pass. It proves only that the number a caller acts on came from the store.
 */
final class AffectedRows
{
    /**
     * @throws UnexpectedValueException when the statement did not yield an affected-row count
     */
    public static function from(mixed $result): int
    {
        if (!\is_int($result)) {
            throw new UnexpectedValueException(\sprintf(
                'A bulk statement must yield an affected-row count, got %s.',
                \get_debug_type($result),
            ));
        }

        return $result;
    }
}
