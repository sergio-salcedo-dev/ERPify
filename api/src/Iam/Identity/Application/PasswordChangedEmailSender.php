<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

/**
 * Sends the "your password was changed" notification after a completed reset — a pure heads-up with no action
 * link and no token, so (unlike the invitation and reset emails) it is safe to produce from an async reactor.
 * If the change was not the recipient's, the copy points them at the monitored security mailbox.
 */
interface PasswordChangedEmailSender
{
    public function send(string $recipientEmail): void;
}
