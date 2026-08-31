<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\IdentityErasureResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What an erasure reports it did, and the one question the CLI asks it.
 *
 * `erasedAnything()` decides whether an operator is told the subject was erased or told there was nothing to
 * erase, so each of its three inputs has to be able to carry the answer ALONE: a run that removed only a
 * recovery secret erased something, and reporting "nothing to erase" there would tell an operator their GDPR
 * request had no subject when it had one.
 *
 * @internal
 */
#[CoversClass(IdentityErasureResult::class)]
final class IdentityErasureResultTest extends TestCase
{
    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    #[Test]
    #[DataProvider('provideEachRemovalCarriesTheAnswerOnItsOwnCases')]
    public function eachRemovalCarriesTheAnswerOnItsOwn(
        bool $identityErased,
        int $resetTokens,
        int $recoverySecrets,
        bool $expected,
    ): void {
        $result = new IdentityErasureResult(self::USER_ID, $identityErased, $resetTokens, $recoverySecrets);

        $this->assertSame($expected, $result->erasedAnything());
    }

    /**
     * @return iterable<string, array{bool, int, int, bool}>
     */
    public static function provideEachRemovalCarriesTheAnswerOnItsOwnCases(): iterable
    {
        yield 'nothing at all' => [false, 0, 0, false];
        yield 'the identity alone' => [true, 0, 0, true];
        yield 'a reset token alone' => [false, 1, 0, true];
        yield 'a recovery secret alone' => [false, 0, 1, true];
        yield 'all three' => [true, 2, 1, true];
    }

    #[Test]
    public function itCarriesTheSubjectAndTheTwoCountsItWasGiven(): void
    {
        $result = new IdentityErasureResult(self::USER_ID, true, 2, 1);

        $this->assertSame(self::USER_ID, $result->userId);
        $this->assertTrue($result->identityErased);
        $this->assertSame(2, $result->resetTokensDeleted);
        $this->assertSame(1, $result->recoverySecretsDeleted);
    }
}
