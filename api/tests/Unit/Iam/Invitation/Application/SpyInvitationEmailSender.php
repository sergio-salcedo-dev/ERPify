<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Application;

use Erpify\Iam\Invitation\Application\InvitationEmailSender;
use Override;

/**
 * Spy {@see InvitationEmailSender} capturing the recipient and the raw accept token, so a test can assert the
 * invite/resend flow delivers the token to the invitee once. Kept local to the Invitation module's tests.
 *
 * @internal
 */
final class SpyInvitationEmailSender implements InvitationEmailSender
{
    /** @var list<array{recipient: string, token: string}> */
    public array $sent = [];

    #[Override]
    public function send(string $recipientEmail, string $acceptToken): void
    {
        $this->sent[] = ['recipient' => $recipientEmail, 'token' => $acceptToken];
    }
}
