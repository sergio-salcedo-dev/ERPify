<?php

declare(strict_types=1);

namespace Erpify\Shared\Token\Domain;

use SensitiveParameter;

/**
 * A freshly minted token: the raw plaintext to hand to the bearer exactly once, paired with the
 * {@see SingleUseToken} digest the consumer persists. The plaintext lives only in transit — never
 * logged, never stored; only its hash survives on {@see SingleUseToken}.
 *
 * The plaintext is a **private** field read solely through {@see plaintext()}: an accidental
 * `json_encode`/log of the whole object emits no public state, so "never logged" is enforced by the
 * type, not by consumer discipline, and every raw-token read is greppable.
 */
final readonly class GeneratedToken
{
    public function __construct(
        #[SensitiveParameter]
        private string $plaintext,
        public SingleUseToken $token,
    ) {
    }

    public function plaintext(): string
    {
        return $this->plaintext;
    }
}
