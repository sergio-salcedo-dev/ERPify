<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Mailer;

use Override;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * In-memory {@see MailerInterface} that captures the last sent message so a test can assert the
 * rendered body.
 *
 * @internal
 */
final class CapturingMailer implements MailerInterface
{
    public ?Email $lastEmail = null;

    public ?Envelope $lastEnvelope = null;

    #[Override]
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->lastEnvelope = $envelope;

        if ($message instanceof Email) {
            $this->lastEmail = $message;
        }
    }
}
