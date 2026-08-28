<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Entity;

use SensitiveParameter;

/**
 * A freshly minted recovery secret: the row to persist, paired with the `<selector>.<secret>` plaintext its
 * owner is shown exactly once. The plaintext exists only in transit — it is never persisted, never logged and
 * never re-derivable, since only its digest reaches {@see RecoverySecret}.
 *
 * The plaintext is a PRIVATE field read through {@see plaintext()}, mirroring
 * {@see \Erpify\Shared\Token\Domain\GeneratedToken}: an accidental `json_encode` or log of the whole object
 * emits no public state, so "shown once, never recorded" is a property of the type rather than of every
 * caller's discipline, and each raw read is greppable.
 *
 * It carries the aggregate rather than a copy of its dates so the minting response can state when the secret
 * was created and when it lapses without a second read — the one screen that ever shows the plaintext must
 * not depend on a follow-up request that can fail after the secret is already unrecoverable.
 */
final readonly class GeneratedRecoverySecret
{
    public function __construct(
        public RecoverySecret $secret,
        #[SensitiveParameter]
        private string $plaintext,
    ) {
    }

    public function plaintext(): string
    {
        return $this->plaintext;
    }
}
