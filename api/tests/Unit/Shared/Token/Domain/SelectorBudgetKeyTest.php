<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Token\Domain;

use Erpify\Shared\Token\Domain\SelectorBudgetKey;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SelectorBudgetKey::class)]
final class SelectorBudgetKeyTest extends TestCase
{
    #[Test]
    public function everySpellingOfOneSelectorKeysOneBucket(): void
    {
        // The property the budget's whole meaning rests on. `Uuid::isValid()` accepts either case and the
        // column is a Postgres-native `uuid`, which compares case-insensitively — so these three spellings
        // are ONE row, and a key that told them apart would hand a caller three times the window's limit.
        // A v7 selector carries 11–12 letters, so the real multiplier is in the thousands.
        $selector = Uuid::generate();

        $keys = [
            SelectorBudgetKey::of($selector),
            SelectorBudgetKey::of(\strtoupper($selector)),
            SelectorBudgetKey::of(\ucfirst($selector)),
        ];

        $this->assertCount(1, \array_unique($keys));
        $this->assertTrue(Uuid::isValid(\strtoupper($selector)), 'the guard admits the spelling being folded');
    }

    #[Test]
    public function itChargesForInputItCannotParse(): void
    {
        // A key that declined to fold what it could not parse would answer, by its own behaviour, which
        // spellings are well-formed — and these budgets exist so that exhaustion is indistinguishable from a
        // dead link. Malformed input gets a key like anything else.
        $this->assertSame('not-a-uuid', SelectorBudgetKey::of('  NOT-A-UUID  '));
        $this->assertSame('', SelectorBudgetKey::of('   '));
    }
}
