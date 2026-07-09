<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Token\Domain;

use DateTimeImmutable;
use Erpify\Shared\Token\Domain\GeneratedToken;
use Erpify\Shared\Token\Domain\SingleUseToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SingleUseToken::class)]
#[CoversClass(GeneratedToken::class)]
final class SingleUseTokenTest extends TestCase
{
    private const string FAR_FUTURE = '2999-01-01T00:00:00+00:00';

    #[Test]
    public function itMintsDistinctHighEntropyPlaintextsEachTime(): void
    {
        $expiresAt = new DateTimeImmutable(self::FAR_FUTURE);

        $first = SingleUseToken::mint($expiresAt);
        $second = SingleUseToken::mint($expiresAt);

        $this->assertNotSame($first->plaintext(), $second->plaintext());
        $this->assertNotSame($first->token->toHash(), $second->token->toHash());
    }

    #[Test]
    public function itEmitsAUrlSafePlaintext(): void
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable(self::FAR_FUTURE));

        // URL-safe base64, no padding: only the token can ride a `?token=` query without escaping.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $generated->plaintext());
    }

    #[Test]
    public function itPersistsOnlyTheHashNeverTheRawToken(): void
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable(self::FAR_FUTURE));

        $this->assertNotSame($generated->plaintext(), $generated->token->toHash());
        // A SHA-256 hex digest at rest — the raw token is unrecoverable from it.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $generated->token->toHash());
    }

    #[Test]
    public function itComparesInConstantTimeAcceptingOnlyTheExactToken(): void
    {
        $now = new DateTimeImmutable('2026-07-08T12:00:00+00:00');
        $generated = SingleUseToken::mint(new DateTimeImmutable(self::FAR_FUTURE));

        // Constant-time compare (\hash_equals) is proven behaviourally, not by measuring wall-clock:
        // the exact token is accepted, and any deviation — one extra char or a different length — is
        // rejected. Timing is not asserted (a wall-clock test would be flaky).
        $this->assertTrue($generated->token->verify($generated->plaintext(), $now));
        $this->assertFalse($generated->token->verify($generated->plaintext() . 'x', $now));
        $this->assertFalse($generated->token->verify('short', $now));
        $this->assertFalse($generated->token->verify('', $now));
    }

    #[Test]
    public function itVerifiesOnlyStrictlyWithinTheTtlWindow(): void
    {
        $expiresAt = new DateTimeImmutable('2026-07-08T12:00:00.000000+00:00');
        $generated = SingleUseToken::mint($expiresAt);

        // Valid strictly before expiry (down to the microsecond); the exact instant and after are expired.
        $this->assertTrue($generated->token->verify($generated->plaintext(), $expiresAt->modify('-1 second')));
        $this->assertTrue($generated->token->verify(
            $generated->plaintext(),
            new DateTimeImmutable('2026-07-08T11:59:59.999999+00:00'),
        ));
        $this->assertFalse($generated->token->verify($generated->plaintext(), $expiresAt));
        $this->assertFalse($generated->token->verify($generated->plaintext(), $expiresAt->modify('+1 second')));
    }

    #[Test]
    public function itTreatsTheExactExpiryInstantAsExpired(): void
    {
        $expiresAt = new DateTimeImmutable('2026-07-08T12:00:00+00:00');
        $generated = SingleUseToken::mint($expiresAt);

        $this->assertTrue($generated->token->isExpired($expiresAt));
        $this->assertFalse($generated->token->isExpired($expiresAt->modify('-1 second')));
    }

    #[Test]
    public function itFailsIndistinguishablyForAnExpiredAndAWrongToken(): void
    {
        $expiresAt = new DateTimeImmutable('2026-07-08T12:00:00+00:00');
        $generated = SingleUseToken::mint($expiresAt);

        $expiredButCorrect = $generated->token->verify($generated->plaintext(), $expiresAt->modify('+1 second'));
        $wrongButUnexpired = $generated->token->verify('not-the-token', $expiresAt->modify('-1 second'));

        // Token opacity (SI-13): both deaths collapse to the same plain false — the API never reveals
        // which cause failed.
        $this->assertFalse($expiredButCorrect);
        $this->assertFalse($wrongButUnexpired);
    }

    #[Test]
    public function itRoundTripsThroughItsPersistedDigest(): void
    {
        $now = new DateTimeImmutable('2026-07-08T12:00:00+00:00');
        $expiresAt = new DateTimeImmutable(self::FAR_FUTURE);
        $generated = SingleUseToken::mint($expiresAt);

        $rehydrated = SingleUseToken::fromHash($generated->token->toHash(), $expiresAt);

        $this->assertTrue($rehydrated->verify($generated->plaintext(), $now));
        $this->assertSame($generated->token->toHash(), $rehydrated->toHash());
        $this->assertSame($expiresAt, $rehydrated->expiresAt());
    }

    #[Test]
    public function itComparesExpiryByInstantNotWallClockAcrossTimezones(): void
    {
        $expiresAt = new DateTimeImmutable('2026-07-08T12:00:00+00:00');
        $generated = SingleUseToken::mint($expiresAt);

        // Same instants as 11:00 and 13:00 UTC, written in +02:00 — a naive wall-clock/string compare
        // would read "13:00 > 12:00 → expired" and wrongly reject the first; instant comparison must not.
        $beforeExpiry = new DateTimeImmutable('2026-07-08T13:00:00+02:00');
        $afterExpiry = new DateTimeImmutable('2026-07-08T15:00:00+02:00');

        $this->assertTrue($generated->token->verify($generated->plaintext(), $beforeExpiry));
        $this->assertFalse($generated->token->verify($generated->plaintext(), $afterExpiry));
    }

    #[Test]
    public function itRejectsAnOverLongPresentedTokenWithoutHashingIt(): void
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable(self::FAR_FUTURE));

        // A hostile over-long input is rejected before it is hashed (never hash an unbounded input);
        // a legitimate token is 43 chars, so this rejects nothing real.
        $this->assertFalse($generated->token->verify(
            \str_repeat('a', 200),
            new DateTimeImmutable('2026-07-08T12:00:00+00:00'),
        ));
    }
}
