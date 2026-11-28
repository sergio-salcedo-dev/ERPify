<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Application\SecondFactorEnabledEmailSender;
use Override;
use Throwable;

/**
 * Recording {@see SecondFactorEnabledEmailSender} that captures recipients and can simulate a send failure.
 *
 * `$sample` runs at the moment of the send and its return value is kept, for the reason
 * {@see RecordingPasswordChangedEmailSender} gives: the only caller catches `Throwable`, so whatever the send must
 * observe is asserted on the far side of that `catch`, never inside the callback.
 *
 * @internal
 */
final class RecordingSecondFactorEnabledEmailSender implements SecondFactorEnabledEmailSender
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
