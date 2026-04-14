<?php

declare(strict_types=1);

namespace Erpify\Shared\Application\Mailer;

/**
 * Outbound port: send operational / transactional notifications.
 *
 * Infrastructure provides adapters (plain text, Twig HTML, logging decorator, null for tests).
 * Handlers should depend on this interface, not a concrete formatter.
 */
interface NotificationMailer
{
    /**
     * @param array<string, mixed> $fields           key/value lines (typically from `DomainEvent::toPrimitives()`)
     * @param null|string          $correlationLabel When set (e.g. event name), implementations may show an "Event: …" line.
     */
    public function send(string $to, string $subject, array $fields, ?string $correlationLabel = null): void;
}
