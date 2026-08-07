<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Application;

use SensitiveParameter;

/**
 * The outcome of issuing an invitation link — {@see SendInvitation} and {@see ResendInvitation} both return it.
 * Two facts with different owners travel together because the caller needs both to decide anything: the raw
 * accept token, and whether the email carrying it got out.
 *
 * **`mailerAccepted` is named for what the sender can actually observe**, which is that
 * {@see SendInvitationEmailBestEffort} called the mailer and it did not throw. It is deliberately not called
 * "delivered": with an async transport the send only reaches a queue, and no mailer confirms a mailbox. A
 * caller may use `false` to prove the invitee will NOT receive the link; it may never read `true` as proof
 * that they did.
 *
 * The pair exists because the send is best-effort and swallows its failure, so without this the invitation is
 * live, undeliverable, and the operator holding the only other copy of the token has no way to learn it.
 */
final readonly class IssuedInvitation
{
    public function __construct(
        #[SensitiveParameter]
        public string $acceptToken,
        public bool $mailerAccepted,
    ) {
    }
}
