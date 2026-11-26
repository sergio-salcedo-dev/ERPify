<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Mail;

use RuntimeException;

/**
 * Raised by {@see CurrentTokenInvitationEmailSender} when the link it was asked to mail is no longer the live one.
 * Never reaches a client: the best-effort wrapper around the sender swallows it, logs it and reports the send as
 * not accepted, which is what the caller needs to say "the link you hold is not the one that was mailed".
 *
 * Its message is fixed and names nothing: not the address, not the token, not the invitation.
 */
final class InvitationLinkSuperseded extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The invitation link is no longer the current one, so it was not mailed.');
    }
}
