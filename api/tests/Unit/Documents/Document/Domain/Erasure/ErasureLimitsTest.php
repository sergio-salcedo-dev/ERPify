<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Domain\Erasure;

use Erpify\Documents\Document\Domain\Erasure\ErasureLimits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The completion sweep's margin is DERIVED, so the bounds it is derived from cannot drift apart from it: the age an
 * entry must reach is the ledger write, plus every lock and statement of the erasure transaction at its own bound,
 * plus the margin. A bound moved without the others would let the sweep steal an erasure whose transaction is still
 * running.
 *
 * @internal
 */
#[CoversClass(ErasureLimits::class)]
final class ErasureLimitsTest extends TestCase
{
    #[Test]
    public function theTransactionBoundIsEveryLockAtItsBoundPlusEveryStatementAtItsBound(): void
    {
        $this->assertSame(
            $this->limit('TRANSACTION_LOCKS') * $this->limit('LOCK_TIMEOUT_MILLISECONDS') / 1_000
                + $this->limit('TRANSACTION_STATEMENTS') * $this->limit('STATEMENT_TIMEOUT_MILLISECONDS') / 1_000,
            $this->limit('TRANSACTION_BOUND_SECONDS'),
            'three locks at 5 s and ten statements at 10 s',
        );
    }

    #[Test]
    public function aLedgerEntryIsLeftAloneForTheWriteTheWholeTransactionAndTheMargin(): void
    {
        $this->assertSame(
            $this->limit('LEDGER_WRITE_TIMEOUT_SECONDS')
                + $this->limit('TRANSACTION_BOUND_SECONDS')
                + $this->limit('CLOCK_SKEW_MARGIN_SECONDS'),
            $this->limit('COMPLETION_MIN_AGE_SECONDS'),
        );
        $this->assertSame(185, $this->limit('COMPLETION_MIN_AGE_SECONDS'));
    }

    #[Test]
    public function theSweepRunsMoreOftenThanTheMarginSoNoEntryWaitsTwoTicks(): void
    {
        $this->assertLessThan(5 * 60, $this->limit('COMPLETION_MIN_AGE_SECONDS'), 'the sweep ticks every five minutes');
    }

    #[Test]
    public function theBoundsAreANamespaceNeverAnInstance(): void
    {
        $class = new ReflectionClass(ErasureLimits::class);

        $this->assertFalse($class->isInstantiable());

        $constructor = $class->getConstructor();
        $this->assertInstanceOf(ReflectionMethod::class, $constructor);
        $this->assertTrue($constructor->isPrivate());

        // Reached through reflection on purpose: the private constructor exists to refuse `new`, and invoking it proves
        // it refuses nothing else (it has no state to build).
        $constructor->invoke($class->newInstanceWithoutConstructor());
    }

    /** Read by name so a constant a compiler could fold is still compared at run time. */
    private function limit(string $name): int
    {
        $value = (new ReflectionClass(ErasureLimits::class))->getConstant($name);
        $this->assertIsInt($value);

        return $value;
    }
}
