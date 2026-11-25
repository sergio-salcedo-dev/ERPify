<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Mail;

use Erpify\Iam\Invitation\Application\InvitationEmailSender;
use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Refuses to mail a link that a later resend has already killed.
 *
 * A resend commits its new token and THEN mails it, outside the transaction, so two resends of one person are
 * serialised by the invitation row lock but their SMTP exchanges are not: the email that arrives last can carry the
 * link the other resend had already replaced. The invitee then holds a dead link in the newest message while the
 * live one sits earlier in the inbox. Checking, immediately before the hand-off, that the link is still the
 * invitation's current one shortens that window from the whole of an SMTP exchange to the gap between a read and
 * a call.
 *
 * **It narrows the race and does not close it** — two sends that both pass the check before either resend commits
 * can still cross, and closing that needs the row held through the SMTP exchange, which would block an accept or a
 * revoke for as long as a mail server takes. That cost was weighed and declined; the residual is recorded in
 * `PRODUCTION_SECURITY_CHECKLIST.md` §7. A messenger-routed send was the other way to order the mails and is closed
 * by policy: the invitee is a person and `Iam.Invitation` is classified so in `api/.persistent-transport-policy`.
 *
 * The check is the invitation's own: the link's selector names the row, the secret is verified against the stored
 * digest in constant time, and the row must still be `SENT` — an accepted, revoked or expired invitation has no
 * link worth delivering. A refusal is an exception the best-effort wrapper above this port already turns into
 * "not accepted", so the caller reports what it did rather than a delivery that did not happen.
 */
#[AsDecorator(decorates: InvitationEmailSender::class)]
final readonly class CurrentTokenInvitationEmailSender implements InvitationEmailSender
{
    public function __construct(
        #[AutowireDecorated]
        private InvitationEmailSender $inner,
        private InvitationRepository $invitations,
        private Clock $clock,
    ) {
    }

    /**
     * @throws InvitationLinkSuperseded when the link is not the live one of a `SENT` invitation
     */
    #[Override]
    public function send(#[SensitiveParameter] string $recipientEmail, #[SensitiveParameter] string $acceptToken): void
    {
        if (!$this->isCurrent($acceptToken)) {
            throw new InvitationLinkSuperseded();
        }

        $this->inner->send($recipientEmail, $acceptToken);
    }

    private function isCurrent(#[SensitiveParameter] string $acceptToken): bool
    {
        $separator = \strpos($acceptToken, '.');

        if (false === $separator) {
            return false;
        }

        $selector = \substr($acceptToken, 0, $separator);
        $secret = \substr($acceptToken, $separator + 1);

        if ('' === $secret || !Uuid::isValid($selector)) {
            return false;
        }

        $invitation = $this->invitations->findById($selector);

        return null !== $invitation
            && InvitationStatus::SENT === $invitation->status()
            && $invitation->verify($secret, $this->clock->now());
    }
}
