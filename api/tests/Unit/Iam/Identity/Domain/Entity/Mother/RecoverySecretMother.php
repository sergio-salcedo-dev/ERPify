<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;

/**
 * A minted {@see RecoverySecret} for tests that need one to exist rather than to exercise how it was made.
 *
 * The three erasure tests each grew their own copy of this two-liner, which is where a builder stops being a
 * local convenience: the mint instant is the input the row's whole caducity derives from, and three copies of
 * it are three places that can silently disagree about whether the seeded secret is live.
 */
final class RecoverySecretMother
{
    /**
     * Far enough back to be unambiguous and far enough forward that the ten-year TTL leaves the row live for
     * any clock a test picks.
     */
    public const string DEFAULT_MINTED_AT = '2026-08-28T13:00:00+00:00';

    public static function mintedFor(
        string $userId = UserMother::DEFAULT_ID,
        string $mintedAt = self::DEFAULT_MINTED_AT,
    ): RecoverySecret {
        return RecoverySecret::mint($userId, new DateTimeImmutable($mintedAt))->secret;
    }
}
