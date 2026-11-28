<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use SensitiveParameter;

/**
 * Sends the "two-step verification was turned on" notification after a factor is enabled — a pure heads-up with
 * no action link, no token, no code and no secret. It is produced post-commit and best-effort by
 * {@see ConfirmTotpFactor} (through {@see SendSecondFactorEnabledEmailBestEffort}), after the identity's other
 * sessions are revoked, so a blocking or failing mailer can neither roll the activation back nor delay the
 * teardown. If the change was not the recipient's, the copy points them at the monitored security mailbox.
 */
interface SecondFactorEnabledEmailSender
{
    public function send(#[SensitiveParameter] string $recipientEmail): void;
}
