<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application\Resource;

use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;

/**
 * Wire contract of the profile view of a recovery secret (`GET /me/recovery-secret`): whether one exists and,
 * if so, when it was minted and when it lapses. Never the secret, which is legible exactly once
 * ({@see MintedRecoverySecretResource}), and — the harder rule — **never the selector**.
 *
 * The selector is the row's primary key and therefore a denial capability: whoever learns it can spend that
 * selector's redemption budget and hold the channel closed in silence, without ever authenticating as anyone.
 * So it may not travel here even though the caller is the owner: this response reaches a browser, a client
 * cache and whatever the client does with it, and none of those is a place a capability belongs.
 *
 * An identity with no secret is answered `exists: false` with two nulls rather than a 404. There is nothing to
 * disclose — the caller is the owner and asked about themself — and a 404 would make the profile screen treat
 * a normal, expected state as a failure.
 *
 * `live` is whether the secret still signs its owner in at the instant of the read: `false` when there is no
 * row, and `false` when the row has lapsed — an expired row still occupies the slot (a mint over it is
 * refused until it is revoked) yet grants nothing. It is the server's rule ({@see RecoverySecret::isLiveAt()})
 * so no client recomputes expiry from `expiresAt` against its own clock; it is a snapshot, not a promise.
 */
final readonly class RecoverySecretResource
{
    public function __construct(
        public bool $exists,
        public bool $live,
        public ?string $mintedAt,
        public ?string $expiresAt,
    ) {
    }
}
