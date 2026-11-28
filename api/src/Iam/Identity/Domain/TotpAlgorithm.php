<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * The time-based one-time password a second factor is verified by (RFC 6238 over RFC 4226): the secret it is
 * drawn from, the step a presented code matches, and the URI an authenticator app enrols from.
 *
 * A port because the algorithm is knowledge belonging to Infrastructure, the way hashing is
 * ({@see \Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher}): the use cases learn "this code matches
 * step N" and nothing about HMAC truncation. It is also the seam a second kind of factor plugs in beside: a
 * WebAuthn adapter verifies an assertion where this verifies a code, and the use cases above it keep their
 * shape — load the factor under lock, ask the port, record what it accepted.
 *
 * The parameters are fixed by this port's contract rather than configurable — HMAC-SHA1, six digits, a 30 second
 * step and a window of one step either side — because an enrolled authenticator computes codes with whatever it
 * was given at enrolment: changing any of them invalidates every enrolled identity at once.
 *
 * Every plaintext secret, every code and the account name are `#[SensitiveParameter]`: a stack trace crossing
 * this port must not print a credential or a person's address.
 */
interface TotpAlgorithm
{
    /**
     * A fresh shared secret as raw bytes, drawn from a CSPRNG. Never persisted in clear: the caller seals it.
     */
    public function newSecret(): string;

    /**
     * The time step `$code` matches at `$at`, within one step either side of the current one and strictly after
     * `$lastAcceptedStep` — so a code accepted once is refused the second time, even inside its window. `null`
     * when nothing matches, including a code that is not six digits.
     */
    public function matchingStep(
        #[SensitiveParameter]
        string $secret,
        #[SensitiveParameter]
        string $code,
        DateTimeImmutable $at,
        ?int $lastAcceptedStep,
    ): ?int;

    /**
     * The secret in the base32 form a person types into an authenticator by hand (RFC 4648, unpadded).
     */
    public function encodedSecret(#[SensitiveParameter] string $secret): string;

    /**
     * The `otpauth://totp/…` URI an authenticator enrols from, carrying the secret and the fixed parameters.
     */
    public function provisioningUri(
        #[SensitiveParameter]
        string $secret,
        #[SensitiveParameter]
        string $accountName,
    ): string;
}
