<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that a session was minted for an admitted identity (`SessionStarted`). Emitted at minting, which is
 * post-admission, so it never reopens the pre-identity enumeration channel. The payload carries only the
 * subject `userId` (the session id is the envelope's aggregateId); `ip`/`device` are deliberately excluded —
 * events stay PII-free, operational data lives only in the registry row.
 */
final class SessionStarted extends DomainEvent
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
        return 'erpify.iam.session.started';
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
