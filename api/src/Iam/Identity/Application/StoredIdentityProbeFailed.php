<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use RuntimeException;
use Throwable;

/**
 * A read {@see StoredIdentityIntegrity} depends on failed, so the inspection reached no verdict. It says
 * nothing about whether the stored identity rows are clean — that is the whole point of having the type.
 *
 * Without it a failed probe would be indistinguishable from a clean sweep, and a control that answers "clean"
 * when its query never ran is worse than no control: it is a green nobody can falsify. The two outcomes are
 * also repaired by different people — a finding is a data repair, this is an infrastructure fault.
 *
 * The message names the column that could not be read and never what it holds.
 */
final class StoredIdentityProbeFailed extends RuntimeException
{
    public static function reading(string $column, Throwable $cause): self
    {
        return new self(
            \sprintf('Could not read "%s", so no verdict was reached about the stored identity rows.', $column),
            0,
            $cause,
        );
    }

    /**
     * The read succeeded and returned something that is not a count. Deliberately not degraded to zero: zero
     * is the value that means "clean", and answering it from a result nobody could interpret would turn an
     * unreadable probe into a clean bill of health — the exact failure this type exists to prevent.
     */
    public static function uncountableResultFrom(string $column): self
    {
        return new self(\sprintf(
            'Reading "%s" returned a value that is not a count, so no verdict was reached.',
            $column,
        ));
    }
}
