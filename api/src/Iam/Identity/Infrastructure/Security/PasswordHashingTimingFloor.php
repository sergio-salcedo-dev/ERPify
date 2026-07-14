<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Application\PreIdentityTimingFloor;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Pays the floor as one password verification of the same algorithm and cost the firewall applies to real
 * credentials (the hasher is resolved by {@see SecurityUser}, exactly as `security.password_hashers` does),
 * so an equalised branch is indistinguishable from a known-email-wrong-password check.
 */
#[AsAlias(PreIdentityTimingFloor::class)]
final class PasswordHashingTimingFloor implements PreIdentityTimingFloor
{
    private const string TIMING_PROBE_INPUT = 'timing-equalisation-probe';

    /**
     * Lazily hashed once, then reused, so every equalised branch spends exactly one password verification —
     * the same cost as a known email with a wrong password — instead of re-hashing on each probe.
     */
    private ?string $dummyHash = null;

    public function __construct(private readonly PasswordHasherFactoryInterface $passwordHasherFactory)
    {
    }

    #[Override]
    public function equalise(): void
    {
        $hasher = $this->passwordHasherFactory->getPasswordHasher(SecurityUser::class);
        $dummyHash = $this->dummyHash ??= $hasher->hash(self::TIMING_PROBE_INPUT);

        $hasher->verify($dummyHash, self::TIMING_PROBE_INPUT);
    }
}
