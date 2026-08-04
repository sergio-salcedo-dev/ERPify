<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use SensitiveParameter;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Turns a plaintext password into the hash the domain stores as an opaque credential. Hashing is an
 * Infrastructure concern, so this is the only place the algorithm lives; it resolves the hasher keyed on
 * {@see SecurityUser} so the CLI-minted hash is verified by the very same algorithm the firewall configures
 * under `security.password_hashers`.
 */
final readonly class PasswordHasher
{
    public function __construct(private PasswordHasherFactoryInterface $passwordHasherFactory)
    {
    }

    public function hash(#[SensitiveParameter] string $plainPassword): string
    {
        return $this->passwordHasherFactory->getPasswordHasher(SecurityUser::class)->hash($plainPassword);
    }

    /**
     * Whether `$plainPassword` is the credential `$hash` stands for — the constant-time comparison the firewall
     * performs at login, made available to the one flow that must re-prove a credential outside it (a signed-in
     * identity changing its own password). It resolves the hasher through the same factory key as {@see hash()},
     * so a stored hash minted by any of the app's paths is verified by the algorithm the firewall configures,
     * and a legacy hash keeps verifying after `auto` moves on.
     *
     * Plaintext never travels past this adapter: callers hand the answer to the domain as a boolean.
     */
    public function verify(#[SensitiveParameter] string $plainPassword, #[SensitiveParameter] string $hash): bool
    {
        return $this->passwordHasherFactory->getPasswordHasher(SecurityUser::class)->verify($hash, $plainPassword);
    }
}
