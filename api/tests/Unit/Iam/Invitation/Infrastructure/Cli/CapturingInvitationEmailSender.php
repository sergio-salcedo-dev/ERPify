<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Infrastructure\Cli;

use Erpify\Iam\Invitation\Application\InvitationEmailSender;
use Override;
use RuntimeException;
use Throwable;

/**
 * An {@see InvitationEmailSender} that captures the raw accept token and can be built to fail afterwards.
 *
 * Capturing **before** the optional throw is the point: it is what lets a command test assert on the exact
 * token in both branches, including the one where the mailer refused the send. Asserting that some UUID-ish
 * string is absent would pass over a regression that printed only the secret half — and the secret half is the
 * part an attacker cannot read out of the database.
 *
 * @internal
 */
final class CapturingInvitationEmailSender implements InvitationEmailSender
{
    public ?string $acceptToken = null;

    private function __construct(private readonly ?Throwable $failure)
    {
    }

    public static function accepting(): self
    {
        return new self(null);
    }

    public static function refusing(): self
    {
        return new self(new RuntimeException('mailer down'));
    }

    #[Override]
    public function send(string $recipientEmail, string $acceptToken): void
    {
        $this->acceptToken = $acceptToken;

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }

    public function capturedToken(): string
    {
        return $this->acceptToken ?? throw new RuntimeException('the flow never attempted a send');
    }

    public function capturedSecret(): string
    {
        $token = $this->capturedToken();

        return \substr($token, \strpos($token, '.') + 1);
    }
}
