<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Infrastructure\Security;

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
}
