<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\PasswordResetEmailSender;
use Override;

/**
 * In-memory {@see PasswordResetEmailSender} recording the recipient and raw `<id>.<secret>` token composite of
 * each send, so a use-case test can assert an email is dispatched only on the ACTIVE path and never otherwise.
 *
 * @internal
 */
final class RecordingPasswordResetEmailSender implements PasswordResetEmailSender
{
    /** @var list<array{email: string, token: string}> */
    public array $sent = [];

    #[Override]
    public function send(string $recipientEmail, string $resetToken): void
    {
        $this->sent[] = ['email' => $recipientEmail, 'token' => $resetToken];
    }
}
