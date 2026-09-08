<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * A pending {@see PasswordResetToken} for tests that need one to exist rather than to exercise how it was
 * issued — the sibling of {@see RecoverySecretMother}, and its counterweight: this row lapses within the
 * hour, so a test seeding one has to say when it expires or it is asserting over an artefact whose liveness
 * it never decided.
 */
final class PasswordResetTokenMother
{
    /**
     * The default window is measured FROM the clock the test is running on, mirroring the `+1 hour` ceiling
     * {@see \Erpify\Iam\Identity\Application\RequestPasswordReset} issues with — which is what the
     * paragraph above claims and an absolute `2030-01-01` literal did not deliver. Pass `expiresAt` to place
     * the row on either side of the boundary.
     */
    public const string DEFAULT_TTL = '+1 hour';

    public static function pendingFor(
        string $userId = UserMother::DEFAULT_ID,
        ?string $expiresAt = null,
        ?string $id = null,
    ): PasswordResetToken {
        $expiry = null === $expiresAt
            ? SystemClock::now()->modify(self::DEFAULT_TTL)
            : new DateTimeImmutable($expiresAt);

        return PasswordResetToken::issue(
            $id ?? Uuid::generate(),
            $userId,
            SingleUseToken::mint($expiry)->token,
        );
    }
}
