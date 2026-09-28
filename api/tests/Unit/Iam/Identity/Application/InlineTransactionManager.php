<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;

/**
 * {@see TransactionManager} that runs the unit of work inline, with no real transaction — the boundary a use
 * case owns without any database. Lets a test exercise the commit path (save + publish) deterministically.
 *
 * {@see self::$committed} is the observable a test needs to tell "after the commit" from "inside it": a
 * post-commit effect asserted only as "it happened" passes just the same when it runs within the transaction.
 *
 * @internal
 */
final class InlineTransactionManager implements TransactionManager
{
    /** True once a unit of work has returned, so an effect can be pinned to the far side of the boundary. */
    public bool $committed = false;

    /**
     * True only while a unit of work is running. `committed` cannot tell "inside" from "not yet entered" —
     * both read false — so an effect that must happen WITHIN the boundary is pinned on this one instead.
     */
    public bool $inside = false;

    #[Override]
    public function transactional(callable $operation): mixed
    {
        $this->inside = true;

        try {
            $result = $operation();
        } finally {
            $this->inside = false;
        }

        $this->committed = true;

        return $result;
    }
}
