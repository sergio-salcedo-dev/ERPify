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
 * lose the accept token it was about to hand over even though the invitation is live.
 *
 * **Swallowing is not the same as hiding, and the return value is what keeps the two apart.** A caller that
 * only learned "the use case returned" could not distinguish a delivered invitation from an undeliverable one,
 * so a console operator would be left with a live invitation, no token on screen and no error — the failure
 * mode a log line reaches ops but never reaches the person standing at the prompt.
 */
final readonly class SendInvitationEmailBestEffort
{
    public function __construct(
        private InvitationEmailSender $sender,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool whether the mailer accepted the send without throwing — never a claim of delivery, which no
     *              mailer confirms and an async transport cannot even attempt within this call
     */
    public function send(#[SensitiveParameter] string $recipientEmail, #[SensitiveParameter] string $acceptToken): bool
    {
        try {
            $this->sender->send($recipientEmail, $acceptToken);
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'Invitation issued; invitation email delivery skipped (send failed).',
                ['exception' => $throwable],
            );

            return false;
        }

        return true;
    }
}
