<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Application\SubjectErasureResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SubjectErasureResult::class)]
final class SubjectErasureResultTest extends TestCase
{
    #[Test]
    public function itExposesWhatWasErased(): void
    {
        $result = new SubjectErasureResult('BankAccount:0197f2b4-0000-7000-8000-000000000000', true, false);

        $this->assertSame('BankAccount:0197f2b4-0000-7000-8000-000000000000', $result->encryptionScopeId);
        $this->assertTrue($result->liveRecordErased);
        $this->assertFalse($result->keyDestroyed);
    }

    #[Test]
    #[TestWith([true, true, true])]
    #[TestWith([true, false, true])]
    #[TestWith([false, true, true])]
    #[TestWith([false, false, false])]
    public function erasedAnythingIsTrueWhenEitherEffectHappened(
        bool $liveRecordErased,
        bool $keyDestroyed,
        bool $expected,
    ): void {
        $result = new SubjectErasureResult('BankAccount:x', $liveRecordErased, $keyDestroyed);

        $this->assertSame($expected, $result->erasedAnything());
    }
}
