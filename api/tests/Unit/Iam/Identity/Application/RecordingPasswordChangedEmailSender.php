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
 * `$observe` fires at the moment of the send, before anything is recorded, so a test can sample the other
 * collaborators exactly then. That is what separates "the notification is sent" — which holds just as well
 * from inside the write transaction, or ahead of the session revocation — from "it is sent after both". An
 * assertion taken once the use case has returned cannot tell those apart.
 *
 * @internal
 */
final class RecordingPasswordChangedEmailSender implements PasswordChangedEmailSender
{
    /** @var list<string> */
    public array $sentTo = [];

    /**
     * @param Closure(): void|null $observe
     */
    public function __construct(
        private readonly ?Throwable $sendFailure = null,
        private readonly ?Closure $observe = null,
    ) {
    }

    #[Override]
    public function send(string $recipientEmail): void
    {
        if ($this->observe instanceof Closure) {
            ($this->observe)();
        }

        if ($this->sendFailure instanceof Throwable) {
            throw $this->sendFailure;
        }

        $this->sentTo[] = $recipientEmail;
    }
}
