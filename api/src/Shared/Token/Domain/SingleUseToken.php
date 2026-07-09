<?php

declare(strict_types=1);

namespace Erpify\Shared\Token\Domain;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * The at-rest digest of a single-use, TTL-bound opaque token: its hash and expiry, never the raw
 * token — that is handed to the bearer once by {@see mint()} and never persisted. Invitation
 * acceptance and password reset share this one building block so their security cannot diverge: one
 * CSPRNG mint, one hash, one constant-time verifier. Verification is opaque — a lapsed or a
 * non-matching token both fail as a plain false, never revealing which.
 *
 * Single-use is the consumer's lifecycle (it retires the digest once accepted); this value owns only
 * the entropy, the hash-at-rest and the constant-time compare.
 */
final readonly class SingleUseToken
{
    /**
     * 256 bits of CSPRNG entropy: the raw token is uniformly random, so a fast cryptographic hash at
     * rest is the correct transform — a slow KDF (bcrypt/argon2) buys nothing against a value that is
     * already infeasible to brute-force, and only taxes every verification.
     */
    private const int ENTROPY_BYTES = 32;

    private const string HASH_ALGO = 'sha256';

    /**
     * A legitimate plaintext is 43 chars (URL-safe base64 of 256 bits); this generous cap rejects a
     * hostile over-long input before it is hashed — a token's length is public, so the early reject
     * leaks nothing, and it bounds the work a caller can force per verification (never hash an
     * unbounded input).
     */
    private const int MAX_PLAINTEXT_LENGTH = 128;

    private function __construct(
        private string $hash,
        private DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * Mints a fresh token: the raw plaintext to deliver once, paired with the digest to persist. The
     * raw token never lives on the returned value object.
     */
    public static function mint(DateTimeImmutable $expiresAt): GeneratedToken
    {
        $raw = \random_bytes(self::ENTROPY_BYTES);
        $plaintext = \sodium_bin2base64($raw, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        return new GeneratedToken($plaintext, new self(self::digest($plaintext), $expiresAt));
    }

    /**
     * Rehydrates a stored digest (the persisted hash + expiry) back into the value object.
     */
    public static function fromHash(string $hash, DateTimeImmutable $expiresAt): self
    {
        return new self($hash, $expiresAt);
    }

    /**
     * True only when the presented token both matches in constant time and has not expired. Any
     * failure — wrong token or lapsed TTL — returns false without distinguishing the cause. Expiry is
     * checked first: it depends on the clock, not on the token bytes, so it leaks nothing about the
     * secret, while the token compare itself stays constant-time via {@see \hash_equals()} over two
     * equal-length digests.
     */
    public function verify(#[SensitiveParameter] string $presentedPlaintext, DateTimeImmutable $now): bool
    {
        if (\strlen($presentedPlaintext) > self::MAX_PLAINTEXT_LENGTH) {
            return false;
        }

        return !$this->isExpired($now) && \hash_equals($this->hash, self::digest($presentedPlaintext));
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function toHash(): string
    {
        return $this->hash;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    private static function digest(#[SensitiveParameter] string $plaintext): string
    {
        return \hash(self::HASH_ALGO, $plaintext);
    }
}
