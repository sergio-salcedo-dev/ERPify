<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Application;

use SensitiveParameter;

/**
 * Outbound port: deliver an invitation email carrying the one-time accept link. The `$acceptToken` is the raw
 * `<invitationId>.<secret>` composite handed to the recipient exactly once — it never persists and never enters
 * a domain event or a log. Infrastructure provides the adapter that renders the bulletproof HTML template and
 * hands the message to the (async) mailer.
 */
interface InvitationEmailSender
{
    public function send(#[SensitiveParameter] string $recipientEmail, #[SensitiveParameter] string $acceptToken): void;
}
