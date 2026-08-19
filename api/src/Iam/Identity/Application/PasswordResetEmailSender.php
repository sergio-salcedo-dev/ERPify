<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use SensitiveParameter;

/**
 * Outbound port: deliver a password-reset email carrying the one-time reset link. The `$resetToken` is the raw
 * `<id>.<secret>` composite handed to the recipient exactly once — it never persists and never enters a domain
 * event or a log. Infrastructure provides the adapter that renders the HTML template and hands the message to
 * the mailer. Mirrors the invitation flow's sender: the raw token rides only in the emailed URL.
 */
interface PasswordResetEmailSender
{
    public function send(#[SensitiveParameter] string $recipientEmail, #[SensitiveParameter] string $resetToken): void;
}
