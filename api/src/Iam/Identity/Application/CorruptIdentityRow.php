<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use RuntimeException;

/**
 * A row of `identity_user` came back holding something an identity id cannot be, so the directory that read it
 * reached no answer.
 *
 * **Dropping the row instead is the failure this type exists to prevent**, and the two directories that read
 * this table both feed controls whose whole output is an absence. A silently discarded id makes
 * {@see \Erpify\Iam\Identity\Domain\Repository\LiveIdentityDirectory} report a live person as erased — a
 * fabricated GDPR divergence — and makes {@see \Erpify\Iam\Identity\Domain\Repository\LockedIdentityDirectory}
 * omit a locked owner from the sweep, so the notice is never sent and nothing anywhere records that it was
 * not. Neither control can distinguish "nothing to report" from "the row was thrown away", which is why the
 * posture here is the one {@see StoredIdentityProbeFailed} takes over the same table: no verdict beats a
 * verdict nobody could falsify.
 *
 * It is not reachable through the schema as it stands — `identity_user.id` is a non-null `uuid` primary key
 * and the driver hands those over as strings — so this states the posture rather than defending a live path.
 * That is the point: the narrowing every reader of this column needs for static analysis has to be spelled
 * *some* way, and the spelling that discards is the one that would quietly become wrong the day the column
 * or the query changes.
 *
 * The message names the column, never what it held.
 */
final class CorruptIdentityRow extends RuntimeException
{
    public static function idIsNotAString(string $column): self
    {
        return new self(\sprintf(
            'Reading "%s" returned a value that is not an identity id, so no directory answer was reached.',
            $column,
        ));
    }
}
