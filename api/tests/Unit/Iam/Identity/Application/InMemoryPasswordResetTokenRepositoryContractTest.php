<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `deleteAllForUser()` is the supersede and the erasure both, and the double stands in for the Doctrine
 * adapter across every use-case unit test in this namespace — so what it returns has to mean what the port
 * promises, no more and no less.
 *
 * **The "no more" half is why these cases exist.** The port declares read-after-delete undefined inside one
 * unit of work, because a DQL bulk `DELETE` does not evict the identity map `find()` consults; this double
 * evicts immediately, so an assertion built on the row having stopped being readable is green here and
 * describes nothing production does. That leaves the returned COUNT as the only thing a caller may act on,
 * and the count is exactly what nothing pinned before: a double answering `1` for a user with two pending
 * rows, or counting somebody else's, would go unnoticed by every consumer.
 *
 * Scoping is the second half and it is not cosmetic — the erasure calls this to make sure no `user_id`
 * linkage outlives an identity, and a delete that reached beyond its argument would take another person's
 * live reset link with it.
 *
 * @internal
 */
#[CoversClass(InMemoryPasswordResetTokenRepository::class)]
final class InMemoryPasswordResetTokenRepositoryContractTest extends TestCase
{
    private const string ANOTHER_USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';

    private const string FIRST_TOKEN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a01';

    private const string SECOND_TOKEN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a02';

    private const string OTHER_TOKEN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a03';

    public function testItCountsEveryRowItRemovedForThatUser(): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository(
            $this->tokenFor(self::FIRST_TOKEN_ID, UserMother::DEFAULT_ID),
            $this->tokenFor(self::SECOND_TOKEN_ID, UserMother::DEFAULT_ID),
        );

        $this->assertSame(
            2,
            $tokens->deleteAllForUser(UserMother::DEFAULT_ID),
            'The count is the only thing this port promises a caller, so a double that under-reports it '
            . 'answers a question no consumer can check anywhere else.',
        );
    }

    public function testItRemovesAndCountsNothingBelongingToAnotherUser(): void
    {
        $other = $this->tokenFor(self::OTHER_TOKEN_ID, self::ANOTHER_USER_ID);
        $tokens = new InMemoryPasswordResetTokenRepository(
            $this->tokenFor(self::FIRST_TOKEN_ID, UserMother::DEFAULT_ID),
            $other,
        );

        $this->assertSame(1, $tokens->deleteAllForUser(UserMother::DEFAULT_ID));
        $this->assertSame(
            $other,
            $tokens->findById(self::OTHER_TOKEN_ID),
            'The erasure calls this to end one identity. Reaching past its argument would take a live reset '
            . 'link belonging to somebody else.',
        );
    }

    public function testItCountsZeroForAUserWithNothingPending(): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository(
            $this->tokenFor(self::OTHER_TOKEN_ID, self::ANOTHER_USER_ID),
        );

        $this->assertSame(0, $tokens->deleteAllForUser(UserMother::DEFAULT_ID));
    }

    private function tokenFor(string $tokenId, string $userId): PasswordResetToken
    {
        return PasswordResetToken::issue(
            $tokenId,
            $userId,
            SingleUseToken::mint(new DateTimeImmutable('2026-07-13T12:30:00+00:00'))->token,
        );
    }
}
