<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Persistence\Double;

use Closure;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Persistence\Domain\Exception\ReferentialIntegrityViolation;
use Override;
use RuntimeException;
use Throwable;

/**
 * Test double for {@see TransactionManager} whose unit of work fails, so a test can drive what a use case
 * does with a rolled-back boundary.
 *
 * Two modes, because where the failure lands changes what the test can see. By default the operation never
 * runs — the boundary refused before entering. {@see self::atCommit()} runs it first and then throws, which
 * is where a foreign key is actually rejected: at the flush, after the callback has issued its writes. Only
 * the second mode leaves the use case's own collaborators exercised, so a test asserting "nothing was
 * published" is making a claim rather than restating that the callback never ran.
 *
 * Neither mode undoes what the operation did — the collaborators are in-memory doubles with no rollback.
 * Real rollback semantics are covered functionally and by Behat against Postgres.
 */
final class FailingTransactionManager implements TransactionManager
{
    /** Units of work entered, so a test can tell "the boundary was reached" from "the guard threw first". */
    public int $opened = 0;

    /** @param ?Closure(callable(): mixed): void $reach null runs nothing — the boundary refused on entry */
    private function __construct(
        private readonly Throwable $failure,
        private readonly ?Closure $reach = null,
    ) {
    }

    /** The boundary refuses before the operation runs. */
    public static function refusing(Throwable $failure): self
    {
        return new self($failure);
    }

    /**
     * The unit of work was refused because another row still references what it touched. Named here rather
     * than assembled per test so a caller's test says which failure it is reacting to, without having to
     * know how the seam builds it.
     */
    public static function referentialIntegrity(): self
    {
        return self::refusing(new ReferentialIntegrityViolation(new RuntimeException('foreign key constraint')));
    }

    /**
     * The referential failure in its production shape: the operation issued its writes and the commit was
     * refused. What a use case sees is only ever this translation — the driver-level exception is the
     * adapter's business, and is pinned in its own tests.
     */
    public static function referentialIntegrityAtCommit(): self
    {
        return self::atCommit(new ReferentialIntegrityViolation(new RuntimeException('foreign key constraint')));
    }

    /**
     * The unit of work ran and the commit was refused — the shape of a flush-time failure, and the only one
     * that leaves the callback's effects observable.
     */
    public static function atCommit(Throwable $failure): self
    {
        return new self($failure, static function (callable $operation): void {
            $operation();
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    #[Override]
    public function transactional(callable $operation): mixed
    {
        ++$this->opened;

        if ($this->reach instanceof Closure) {
            ($this->reach)($operation);
        }

        throw $this->failure;
    }
}
