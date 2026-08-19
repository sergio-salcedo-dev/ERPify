<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * Best-effort wrapper around {@see PasswordChangedEmailSender} for the post-commit notification of a completed
 * reset: it swallows (and logs for ops) any mailer failure instead of letting it surface. The credential change
 * has already committed and the sessions have already been revoked by the time this runs, so raising here would
 * answer a successful reset with a 500 and tell the caller their password never changed. Owning the policy in
 * its own collaborator keeps {@see CompletePasswordReset} free of it, mirroring {@see RevokeSessionsBestEffort}.
 */
final readonly class SendPasswordChangedEmailBestEffort
{
    public function __construct(
        private PasswordChangedEmailSender $sender,
        private LoggerInterface $logger,
    ) {
    }

    public function send(#[SensitiveParameter] string $recipientEmail): void
    {
        try {
            $this->sender->send($recipientEmail);
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'Credential change committed; password-changed notification skipped (send failed).',
                ['exception' => $throwable],
            );
        }
    }
}
