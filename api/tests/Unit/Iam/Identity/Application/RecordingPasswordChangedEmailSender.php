<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Application\PasswordChangedEmailSender;
use Override;
use Throwable;

/**
 * Recording {@see PasswordChangedEmailSender} that captures recipients and can simulate a send failure.
 *
 * `$sample` runs at the moment of the send and its return value is kept, so a test can read the state of the
 * other collaborators exactly then. That is what separates "the notification is sent" — which holds just as
 * well from inside the write transaction, or ahead of the session revocation — from "it is sent after both".
 * An assertion taken once the use case has returned cannot tell those apart.
 *
 * It samples rather than asserting in place, and that is load-bearing: the only caller of this port is
 * {@see \Erpify\Iam\Identity\Application\SendPasswordChangedEmailBestEffort}, which catches `Throwable`. A
 * PHPUnit assertion failing inside the callback would be swallowed exactly like a mailer fault and the test
 * would pass — verified by deliberately failing one. Whatever is observed here has to be asserted on the far
 * side of that `catch`.
 *
 * @internal
 */
final class RecordingPasswordChangedEmailSender implements PasswordChangedEmailSender
{
    /** @var list<string> */
    public array $sentTo = [];

    /** @var list<mixed> one entry per send, in order */
    public array $samples = [];

    /**
     * @param Closure(): mixed|null $sample
     */
    public function __construct(
        private readonly ?Throwable $sendFailure = null,
        private readonly ?Closure $sample = null,
    ) {
    }

    #[Override]
    public function send(string $recipientEmail): void
    {
        if ($this->sample instanceof Closure) {
            $this->samples[] = ($this->sample)();
        }

        if ($this->sendFailure instanceof Throwable) {
            throw $this->sendFailure;
        }

        $this->sentTo[] = $recipientEmail;
    }
}
