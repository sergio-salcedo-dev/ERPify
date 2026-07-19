<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that a single session was revoked (`ACTIVE → REVOKED`). The payload carries only the subject
 * `userId`; the revoked session id is the envelope's aggregateId. No `ip`/`device` — the event stays PII-free.
 */
final class SessionRevoked extends DomainEvent
{
    public function __construct(
        string $aggregateId,
        private readonly string $userId,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn);
    }

    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.session.revoked';
    }

    #[Override]
    public static function aggregateType(): string
    {
        return 'Iam.Session';
    }

    /**
     * @return array{userId: string}
     */
    #[Override]
    public function toPrimitives(): array
    {
        return ['userId' => $this->userId];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Override]
    public static function fromPrimitives(
        string $aggregateId,
        array $body,
        string $eventId,
        string $occurredOn,
    ): static {
        $userId = self::stringMember($body, 'userId');

        return new self($aggregateId, $userId, $eventId, new DateTimeImmutable($occurredOn));
    }

    public function userId(): string
    {
        return $this->userId;
    }
}
