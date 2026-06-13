<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Infrastructure\Messenger;

use Erpify\Shared\Application\Mailer\NotificationMailer;
use Override;
use Throwable;

/**
 * In-memory {@see NotificationMailer} that records the last send and can simulate a transport
 * failure.
 *
 * @internal
 */
final class RecordingNotificationMailer implements NotificationMailer
{
    public int $sendCalls = 0;

    public ?string $lastSubject = null;

    public function __construct(
        private readonly ?Throwable $sendFailure = null,
    ) {
    }

    #[Override]
    public function send(string $to, string $subject, array $fields, ?string $correlationLabel = null): void
    {
        ++$this->sendCalls;
        $this->lastSubject = $subject;

        if ($this->sendFailure instanceof Throwable) {
            throw $this->sendFailure;
        }
    }
}
