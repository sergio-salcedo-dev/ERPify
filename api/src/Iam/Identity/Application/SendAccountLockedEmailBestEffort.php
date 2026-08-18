<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * Best-effort wrapper around {@see AccountLockedEmailSender}, mirroring
 * {@see SendPasswordChangedEmailBestEffort}. It reports whether the send actually succeeded, which is the
 * one way it differs from its siblings and the reason it exists at all.
 *
 * The caller stamps the suppression window only on a `true`. Sealing regardless would let a mailer outage buy
 * a full day of silence about a live lockout; the accepted cost of the other order is a duplicate notice when
 * delivery succeeds and the stamp that follows it does not — at-least-once after a confirmed send, never
 * at-most-once.
 *
 * `SendEmailMessage` is deliberately unrouted, so this is a synchronous SMTP round trip. It runs in a
 * maintenance tick and never on a request, which is what keeps its latency off the login path it observes.
 */
final readonly class SendAccountLockedEmailBestEffort
{
    public function __construct(
        private AccountLockedEmailSender $sender,
        private LoggerInterface $logger,
    ) {
    }

    public function send(#[SensitiveParameter] string $recipientEmail): bool
    {
        try {
            $this->sender->send($recipientEmail);

            return true;
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'Identity locked; lockout notification skipped (send failed).',
                ['exception' => $throwable],
            );

            return false;
        }
    }
}
