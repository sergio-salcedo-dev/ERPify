<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Iam\Identity\Domain\Event\PasswordResetRequested;
use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
use SensitiveParameter;

/**
 * The at-rest record of a pending password reset: the user it belongs to, the {@see SingleUseToken} digest a
 * presented secret is verified against, and the shared expiry. State-oriented and deliberately minimal — it
 * carries no PII and never the raw token, so a leaked row grants nothing. Single-use is the consumer's
 * lifecycle: {@see \Erpify\Iam\Identity\Application\CompletePasswordReset} removes the row in the SAME
 * transaction that fixes the new password (retire-then-act), and a re-request supersedes any pending row via
 * {@see \Erpify\Iam\Identity\Domain\Repository\PasswordResetTokenRepository::deleteAllForUser()}. Caducity is
 * the token's own axis ({@see verify()} rejects a lapsed secret), so there is no persisted status to sweep and
 * hard delete is the whole lifecycle.
 *
 * A separate aggregate rather than columns on {@see User}: recovery bookkeeping is not identity, and keeping it
 * out of `identity_user` leaves the identity aggregate untouched by the forgot path and confines the reset
 * schema to its own table.
 *
 * The link the bearer receives is a selector-verifier `<id>.<secret>`: the id (this row's PK, not a secret)
 * SELECTS the row, the secret is VERIFIED constant-time against the digest — so the lookup needs no hash index
 * and {@see SingleUseToken} keeps its digest private (no hash-lookup accessor to add).
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_password_reset_token')]
#[ORM\Index(name: 'idx_identity_password_reset_token_user_id', columns: ['user_id'])]
final class PasswordResetToken extends AggregateRoot
{
    #[ORM\Column(name: 'user_id', type: Types::GUID)]
    private string $userId;

    #[ORM\Column(name: 'token_hash')]
    private string $tokenHash;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    private function __construct(string $id, string $userId, SingleUseToken $token)
    {
        parent::__construct();

        Uuid::ensure($id);
        Uuid::ensure($userId);

        $this->id = $id;
        $this->userId = $userId;
        $this->tokenHash = $token->toHash();
        $this->expiresAt = $token->expiresAt();
    }

    /**
     * Records a freshly minted reset token: only the digest and expiry of `$token` are kept, never its raw
     * secret. The caller holds the plaintext just long enough to deliver the link, then drops it. Issuing IS the
     * "a reset was requested" fact, so the aggregate records {@see PasswordResetRequested} at its source (mirror
     * of {@see User} recording its own credential events) — PII-free, only the user id.
     */
    public static function issue(string $id, string $userId, SingleUseToken $token): self
    {
        $reset = new self($id, $userId, $token);
        $reset->record(new PasswordResetRequested($userId));

        return $reset;
    }

    /**
     * Constant-time check that `$presentedSecret` matches this row's digest and has not expired. Rehydrates the
     * persisted digest into a {@see SingleUseToken} and delegates — a lapsed or a non-matching secret both fail
     * as a plain false without revealing which (opaque, mirroring {@see SingleUseToken::verify()}).
     */
    public function verify(#[SensitiveParameter] string $presentedSecret, DateTimeImmutable $now): bool
    {
        return SingleUseToken::fromHash($this->tokenHash, $this->expiresAt)->verify($presentedSecret, $now);
    }

    public function userId(): string
    {
        return $this->userId;
    }
}
