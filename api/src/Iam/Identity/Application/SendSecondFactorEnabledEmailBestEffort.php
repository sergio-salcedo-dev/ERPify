<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * Best-effort wrapper around {@see SecondFactorEnabledEmailSender} for the post-commit notification of an enabled
 * second factor: it swallows (and reports for ops) any mailer failure instead of letting it surface. The factor is
 * already enabled and the other sessions already revoked by the time this runs, so raising here would answer a
 * successful activation with a 500 and tell the owner their factor was not enabled. Mirrors
 * {@see SendPasswordChangedEmailBestEffort}; the report goes to the always-on `observability` channel, bound in
 * `services.yaml`.
 */
final readonly class SendSecondFactorEnabledEmailBestEffort
{
    public function __construct(
        private SecondFactorEnabledEmailSender $sender,
        private LoggerInterface $logger,
    ) {
    }

    public function send(#[SensitiveParameter] string $recipientEmail): void
    {
        try {
            $this->sender->send($recipientEmail);
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'Second factor enabled; enabled notification skipped (send failed).',
                ['exception' => $throwable],
            );
        }
    }
}
