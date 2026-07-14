<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Application;

use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * Best-effort wrapper around {@see InvitationEmailSender} for the post-commit send of the invitation flow: it
 * swallows (and logs for ops) any mailer failure instead of letting it surface. The invitation state is already
 * committed when the send runs, so a raised mailer fault would abort the caller AFTER the flip — the CLI would
 * lose the accept token it was about to echo even though the invitation is live. Swallowing lets the caller
 * still hand the token over; the operator resends if the mail never arrived.
 */
final readonly class SendInvitationEmailBestEffort
{
    public function __construct(
        private InvitationEmailSender $sender,
        private LoggerInterface $logger,
    ) {
    }

    public function send(string $recipientEmail, #[SensitiveParameter] string $acceptToken): void
    {
        try {
            $this->sender->send($recipientEmail, $acceptToken);
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'Invitation issued; invitation email delivery skipped (send failed).',
                ['exception' => $throwable],
            );
        }
    }
}
