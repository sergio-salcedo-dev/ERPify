<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application\Resource;

use SensitiveParameter;

/**
 * Wire contract of the ONE response that ever carries a recovery secret in clear (`POST /me/recovery-secret`).
 * It is a separate view from {@see RecoverySecretResource}, and the split is the point rather than DTO
 * bookkeeping: the two differ by exactly the field that must never appear twice, so a single shared shape
 * would put an optional plaintext on the endpoint that lists the secret and make "shown once" depend on a
 * mapper remembering to leave it null.
 *
 * `secret` is the `<selector>.<secret>` the owner must store away from the machine. It is not persisted, not
 * logged, and not re-derivable — only its digest reaches the row — so a lost response means a revoke and a
 * fresh mint, never a way to read this value again.
 *
 * `expiresAt` travels beside it deliberately. The ten-year window is the only way this channel dies without
 * anyone acting, and a holder who was never told the date cannot plan around it; showing it here and on the
 * profile screen is what turns an accepted risk into a governable one.
 */
final readonly class MintedRecoverySecretResource
{
    public function __construct(
        #[SensitiveParameter]
        public string $secret,
        public string $mintedAt,
        public string $expiresAt,
    ) {
    }
}
