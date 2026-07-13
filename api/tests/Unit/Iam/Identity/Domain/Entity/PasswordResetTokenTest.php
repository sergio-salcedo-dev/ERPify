<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Shared\Token\Domain\SingleUseToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * @internal
 */
#[CoversClass(PasswordResetToken::class)]
final class PasswordResetTokenTest extends TestCase
{
    private const string TOKEN_ID = '0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e01';

    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    public function testVerifiesTheMintedSecretWithinTheTtl(): void
    {
        $now = new DateTimeImmutable('2026-07-13T12:00:00+00:00');
        $generated = SingleUseToken::mint($now->modify('+1 hour'));
        $token = PasswordResetToken::issue(self::TOKEN_ID, self::USER_ID, $generated->token);

        $this->assertTrue($token->verify($generated->plaintext(), $now));
    }

    public function testRejectsAWrongSecret(): void
    {
        $now = new DateTimeImmutable('2026-07-13T12:00:00+00:00');
        $generated = SingleUseToken::mint($now->modify('+1 hour'));
        $token = PasswordResetToken::issue(self::TOKEN_ID, self::USER_ID, $generated->token);

        $this->assertFalse($token->verify('not-the-secret', $now));
    }

    public function testRejectsTheCorrectSecretOnceExpired(): void
    {
        $now = new DateTimeImmutable('2026-07-13T12:00:00+00:00');
        $generated = SingleUseToken::mint($now->modify('+1 hour'));
        $token = PasswordResetToken::issue(self::TOKEN_ID, self::USER_ID, $generated->token);

        $this->assertFalse($token->verify($generated->plaintext(), $now->modify('+2 hours')));
    }

    public function testStoresTheDigestNeverThePlaintext(): void
    {
        $now = new DateTimeImmutable('2026-07-13T12:00:00+00:00');
        $generated = SingleUseToken::mint($now->modify('+1 hour'));
        $token = PasswordResetToken::issue(self::TOKEN_ID, self::USER_ID, $generated->token);

        $storedHash = (new ReflectionProperty(PasswordResetToken::class, 'tokenHash'))->getValue($token);

        $this->assertIsString($storedHash);
        $this->assertSame(\hash('sha256', $generated->plaintext()), $storedHash);
        $this->assertNotSame($generated->plaintext(), $storedHash);
    }

    public function testExposesTheUserId(): void
    {
        $token = PasswordResetToken::issue(
            self::TOKEN_ID,
            self::USER_ID,
            SingleUseToken::mint(new DateTimeImmutable('2026-07-13T13:00:00+00:00'))->token,
        );

        $this->assertSame(self::USER_ID, $token->userId());
    }
}
