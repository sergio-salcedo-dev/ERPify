<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Application\AccountLockedEmailSender;
use Override;
use RuntimeException;

/**
 * Recording {@see AccountLockedEmailSender} that captures recipients, can fail for chosen ones, and samples
 * the state of the other collaborators at the instant of the send.
 *
 * Failing **per recipient** rather than globally is what lets a test put the fault in the middle of a sweep:
 * a sender that either always works or always throws cannot distinguish "the sweep continues past a failed
 * row" from "the sweep sends to nobody".
 *
 * `$sample` runs at the moment of the send and its return value is kept, so a test can read the repository
 * exactly then. That is what separates "the stamp is written" — true whether it happens before or after the
 * send — from "the stamp is written *after* a send that succeeded", and only the second is the contract: the
 * other order turns a mailer outage into a day of silence about a live lockout.
 *
 * It samples rather than asserting in place, and that is load-bearing: the only caller of this port is
 * {@see \Erpify\Iam\Identity\Application\SendAccountLockedEmailBestEffort}, which catches `Throwable`. A
 * PHPUnit assertion failing inside the callback would be swallowed exactly like a mailer fault and the test
 * would pass. Whatever is observed here has to be asserted on the far side of that `catch`.
 *
 * @internal
 */
final class RecordingAccountLockedEmailSender implements AccountLockedEmailSender
{
    /** @var list<string> recipients of sends that were not made to fail, in order */
    public array $sentTo = [];

    /** @var list<string> every recipient the sweep attempted, failures included */
    public array $attempted = [];

    /** @var list<mixed> one entry per attempt, in order */
    public array $samples = [];

    /**
     * @param list<string>          $failingRecipients addresses whose send throws
     * @param Closure(): mixed|null $sample
     */
    public function __construct(
        private readonly array $failingRecipients = [],
        private readonly ?Closure $sample = null,
    ) {
    }

    #[Override]
    public function send(string $recipientEmail): void
    {
        $this->attempted[] = $recipientEmail;

        if ($this->sample instanceof Closure) {
            $this->samples[] = ($this->sample)();
        }

        if (\in_array($recipientEmail, $this->failingRecipients, true)) {
            throw new RuntimeException('smtp refused the message');
        }

        $this->sentTo[] = $recipientEmail;
    }
}
