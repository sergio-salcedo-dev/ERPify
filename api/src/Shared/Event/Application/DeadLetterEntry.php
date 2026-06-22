<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

use DateTimeImmutable;

/**
 * A single message resting in the failure transport, projected to the fields an operator needs to
 * triage it: what failed, when it was last redelivered, and why. Decoupled from Messenger stamps so
 * inspection and alerting logic stays free of the transport.
 */
final readonly class DeadLetterEntry
{
    public function __construct(
        public string $messageType,
        public ?string $eventId,
        public ?DateTimeImmutable $failedAt,
        public ?string $errorClass,
        public ?string $errorMessage,
    ) {
    }
}
