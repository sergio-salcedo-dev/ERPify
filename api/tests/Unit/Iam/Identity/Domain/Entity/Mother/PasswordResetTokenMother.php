<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
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
    public const string DEFAULT_EXPIRES_AT = '2030-01-01T00:00:00+00:00';

    public static function pendingFor(
        string $userId = UserMother::DEFAULT_ID,
        string $expiresAt = self::DEFAULT_EXPIRES_AT,
        ?string $id = null,
    ): PasswordResetToken {
        return PasswordResetToken::issue(
            $id ?? Uuid::generate(),
            $userId,
            SingleUseToken::mint(new DateTimeImmutable($expiresAt))->token,
        );
    }
}
