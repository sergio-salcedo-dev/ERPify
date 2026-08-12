<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Persistence\Double;

use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Persistence\Domain\Exception\ReferentialIntegrityViolation;
use Override;
use RuntimeException;
use Throwable;

/**
 * Test double for {@see TransactionManager} whose unit of work fails, so a test can drive what a use case
 * does with a rolled-back boundary.
 *
 * **It does not run the operation.** What a caller sees from a failed unit of work is the exception and an
 * empty database, and that is what this reproduces; whether the callback got part-way before the commit was
 * refused is the boundary's own business, and asserting it here would pin the double's design rather than
 * the use case's. Real rollback semantics — writes issued and then discarded — are covered functionally and
 * by Behat against Postgres.
 */
final class FailingTransactionManager implements TransactionManager
{
    /** Units of work entered, so a test can tell "the boundary was reached" from "the guard threw first". */
    public int $opened = 0;

    public function __construct(private readonly Throwable $failure)
    {
    }

    /**
     * The unit of work was refused because another row still references what it touched. Named here rather
     * than assembled per test so a caller's test says which failure it is reacting to, without having to
     * know how the seam builds it.
     */
    public static function referentialIntegrity(): self
    {
        return new self(new ReferentialIntegrityViolation(new RuntimeException('foreign key constraint')));
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

        throw $this->failure;
    }
}
