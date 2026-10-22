<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity;

use DateInterval;
use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "Live" is the same rule redemption applies, read at the same boundary: the instant of expiry itself already
 * counts as lapsed. The boundary case is the one with teeth — a `>` where the token primitive says `>=` would
 * call a secret live in the one instant it can no longer be redeemed.
 *
 * @internal
 */
#[CoversClass(RecoverySecret::class)]
final class RecoverySecretTest extends TestCase
{
    private const string NOW = '2026-08-28T12:00:00+00:00';

    #[Test]
    public function aSecretIsLiveBeforeItsExpiry(): void
    {
        $secret = RecoverySecret::mint(UserMother::DEFAULT_ID, new DateTimeImmutable(self::NOW))->secret;

        $this->assertTrue($secret->isLiveAt(new DateTimeImmutable(self::NOW)));
        $this->assertTrue($secret->isLiveAt($secret->expiresAt()->sub(new DateInterval('PT1S'))));
    }

    #[Test]
    public function aSecretIsNoLongerLiveAtTheExactInstantOfItsExpiry(): void
    {
        $secret = RecoverySecret::mint(UserMother::DEFAULT_ID, new DateTimeImmutable(self::NOW))->secret;

        $this->assertFalse($secret->isLiveAt($secret->expiresAt()));
    }

    #[Test]
    public function aSecretIsNoLongerLiveAfterItsExpiry(): void
    {
        $secret = RecoverySecret::mint(UserMother::DEFAULT_ID, new DateTimeImmutable(self::NOW))->secret;

        $this->assertFalse($secret->isLiveAt($secret->expiresAt()->add(new DateInterval('PT1S'))));
    }
}
