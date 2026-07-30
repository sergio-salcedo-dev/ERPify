<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

/**
 * Sends the "your password was changed" notification after a completed reset — a pure heads-up with no action
 * link and no token. It is produced post-commit and best-effort by {@see CompletePasswordReset} (through
 * {@see SendPasswordChangedEmailBestEffort}), last of the flow's steps, so a blocking or failing mailer can
 * neither roll the reset back nor delay the session teardown. If the change was not the recipient's, the copy
 * points them at the monitored security mailbox.
 */
interface PasswordChangedEmailSender
{
    public function send(string $recipientEmail): void;
}
