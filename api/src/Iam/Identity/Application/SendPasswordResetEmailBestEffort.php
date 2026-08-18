<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * Best-effort wrapper around {@see PasswordResetEmailSender} for the post-commit send of the "forgot my password"
 * flow: it swallows (and logs for ops) any mailer failure instead of letting it surface. Only an `ACTIVE`
 * identity reaches the send, so a raised mailer fault would turn that path's uniform 202 into a 500 while
 * unknown accounts still see 202 — an enumeration oracle. Swallowing keeps the response identical whether or not
 * a send was even attempted.
 */
final readonly class SendPasswordResetEmailBestEffort
{
    public function __construct(
        private PasswordResetEmailSender $sender,
        private LoggerInterface $logger,
    ) {
    }

    public function send(#[SensitiveParameter] string $recipientEmail, #[SensitiveParameter] string $resetToken): void
    {
        try {
            $this->sender->send($recipientEmail, $resetToken);
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'Password-reset requested; reset email delivery skipped (send failed).',
                ['exception' => $throwable],
            );
        }
    }
}
