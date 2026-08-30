<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Entity;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Iam\Identity\Domain\Event\RecoverySecretMinted;
use Erpify\Iam\Identity\Domain\Event\RecoverySecretRedeemed;
use Erpify\Iam\Identity\Domain\Event\RecoverySecretRevoked;
use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Privacy\Domain\PersonSubjectReference;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
use SensitiveParameter;

/**
 * The at-rest record of an identity's recovery secret: the user it belongs to, the {@see SingleUseToken}
 * digest a presented secret is verified against, and its expiry. The plaintext is handed to the owner once,
 * in the response that mints it, and never persisted, logged or re-shown.
 *
 * It is a SECOND CREDENTIAL, not an extension of the password. It survives a password rotation, it is not
 * rotated when it is spent, and it stops being redeemable only by redemption, revocation, expiry or subject
 * erasure — so holding it is equivalent to holding a recovery credential until one of those four. Three of
 * them remove the row; **expiry does not**. Nothing sweeps a lapsed secret — no maintenance schedule member,
 * no `deleteExpired` on the port — so a lapsed row, and the person reference it carries, outlives the
 * capability it once granted and leaves by erasure alone. The profile surface
 * that lists it (minted at, expires at) with an explicit revoke is what makes that governable: eviction is
 * visible, never a silent side effect of some unrelated act.
 *
 * A separate aggregate rather than a `purpose` discriminator on {@see PasswordResetToken}: that table's
 * erasure owner calls `deleteAllForUser()` unqualified, and the person-reference gate proves a detective
 * source EXISTS, never that its query reads the right column — so sharing the table would put a GDPR hole
 * exactly where this repo's own control is blind. One row per identity ({@see $userId} is unique), so a
 * second mint is refused rather than silently superseding the secret its owner may already have written down.
 *
 * At most ONE CONSUMPTION IS PERSISTED, and that is the whole meaning of "single use" here. It is not "at
 * most one authentication": the redeeming flow establishes the session BEFORE it retires the row, so a
 * failure between the two leaves the secret live and re-redeemable, which is the direction that keeps a
 * signed-out sole administrator from being stranded by a partial failure. Serialisation is the consumer's:
 * the decision to consume is taken on a `FOR UPDATE` re-read, never on the resolving lookup.
 *
 * The primary key IS the selector half of the presented `<selector>.<secret>` — the selector SELECTS the row
 * and the secret is VERIFIED constant-time against the digest, so the lookup needs no hash index. That makes
 * the key a **denial capability**: whoever learns it can spend that selector's redemption budget and hold the
 * channel shut in silence, which is why it may reach no event, audit row, log line, DTO or URL. It is a
 * `symfony/uid` UUID v7, and its unpredictability is asserted no further than the code earns: v7 seeds from
 * `random_bytes(16)` and then, within the same millisecond, INCREMENTS the random part by 24 bits of a
 * SHA-512 chain over that seed rather than drawing again. What closes the gap is the threat model rather than
 * the entropy — guessing a selector buys denial and never authentication, since the secret is the
 * authenticator, and that denial is dominated by the cheaper email-keyed lockout attack this channel exists
 * to answer.
 *
 * Its TTL is ten years, and that is a decision rather than a consequence of {@see SingleUseToken} demanding
 * one. A short TTL would reintroduce by the back door the silent destruction rejected when deciding that a
 * password change leaves a live secret standing: the holder is by construction someone with no shell and no
 * second administrator to notice. The residual — a credential valid for a decade, whose only detection of
 * theft is the owner seeing it disappear — is tracked as an open, searchable record rather than settled by
 * this paragraph. @accepted-risk #870
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_recovery_secret')]
#[ORM\UniqueConstraint(name: 'uniq_identity_recovery_secret_user_id', columns: ['user_id'])]
final class RecoverySecret extends AggregateRoot
{
    /**
     * How long a minted secret stays redeemable. Ten years is the honest spelling of "it does not expire"
     * under a primitive that makes no TTL unrepresentable, and it is visible in the schema rather than
     * hidden in a mint call site.
     */
    public const string RECOVERY_SECRET_TTL = 'P10Y';

    #[ORM\Column(name: 'user_id', type: Types::GUID)]
    #[PersonSubjectReference(erasedBy: 'src/Iam/Identity/Application/EraseIdentitySubject.php')]
    private string $userId;

    #[ORM\Column(name: 'secret_hash')]
    private string $secretHash;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    private function __construct(string $selector, string $userId, SingleUseToken $secret)
    {
        parent::__construct();

        Uuid::ensure($selector);
        Uuid::ensure($userId);

        $this->id = $selector;
        $this->userId = $userId;
        $this->secretHash = $secret->toHash();
        $this->expiresAt = $secret->expiresAt();
    }

    /**
     * Mints a secret for `$userId`: draws BOTH halves of the credential, keeps only the digest and the expiry,
     * and hands back the plaintext for the single response that will ever carry it.
     *
     * Both halves, and that is the reason the selector is drawn here rather than assigned by the caller as
     * every other aggregate's id is. A selector is not merely this row's key: it is half of what the bearer
     * presents, and the other half never leaves this method. Splitting their generation across two layers
     * would mean the one string a person has to keep is assembled from two places, and the layer holding the
     * secret would be trusting the layer holding the selector to have drawn it the right way. `symfony/uid`
     * is the sanctioned exception that lets the domain do this without reaching for infrastructure.
     *
     * The TTL is this class's policy for the same reason: a caller reaching for the token primitive and the
     * expiry separately would be reassembling a rule the aggregate already holds, and the day a second caller
     * appears is the day the two spellings of it begin to differ.
     */
    public static function mint(string $userId, DateTimeImmutable $now): GeneratedRecoverySecret
    {
        $selector = Uuid::generate();
        $generated = SingleUseToken::mint($now->add(new DateInterval(self::RECOVERY_SECRET_TTL)));

        $recoverySecret = new self($selector, $userId, $generated->token);
        $recoverySecret->record(new RecoverySecretMinted($userId));

        return new GeneratedRecoverySecret($recoverySecret, $selector . '.' . $generated->plaintext());
    }

    /**
     * Constant-time check that `$presentedSecret` matches this row's digest and has not expired. A lapsed and
     * a non-matching secret both fail as a plain false without revealing which, mirroring
     * {@see SingleUseToken::verify()} — the caller folds both into one opaque refusal.
     */
    public function verify(#[SensitiveParameter] string $presentedSecret, DateTimeImmutable $now): bool
    {
        return SingleUseToken::fromHash($this->secretHash, $this->expiresAt)->verify($presentedSecret, $now);
    }

    /**
     * Records that this secret was spent — the holder proved possession and a session was established for
     * its owner.
     *
     * It mutates nothing, and that is the aggregate being honest rather than a method with no body's worth
     * of work: the state change IS the row's disappearance. A consumed secret is deleted instead of flagged,
     * because retaining it would keep a live reference to a person alive for the sake of a status nobody
     * reads, and single use comes from the `FOR UPDATE` re-read the caller decides on rather than from a
     * column. What the aggregate owns here is the FACT, and it has to be recorded before the repository
     * removes the row, because after that there is nothing left to pull the events from.
     */
    public function redeem(): void
    {
        $this->record(new RecoverySecretRedeemed($this->userId));
    }

    /**
     * Records that the owner destroyed this secret deliberately. Same shape and same reason as
     * {@see redeem()}: the transition is the row's removal, the aggregate owns the fact.
     */
    public function revoke(): void
    {
        $this->record(new RecoverySecretRevoked($this->userId));
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
