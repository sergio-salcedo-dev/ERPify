<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Messenger;

use Erpify\Iam\Identity\Application\PasswordChangedEmailSender;
use Override;
use Throwable;

/**
 * Recording {@see PasswordChangedEmailSender} that captures recipients and can simulate a send failure, so a
 * test can pin the reactor's claim/send/release flow without a mailer.
 *
 * @internal
 */
final class RecordingPasswordChangedEmailSender implements PasswordChangedEmailSender
{
    /** @var list<string> */
    public array $sentTo = [];

    public function __construct(private readonly ?Throwable $sendFailure = null)
    {
    }

    #[Override]
    public function send(string $recipientEmail): void
    {
        if ($this->sendFailure instanceof Throwable) {
            throw $this->sendFailure;
        }

        $this->sentTo[] = $recipientEmail;
    }
}
