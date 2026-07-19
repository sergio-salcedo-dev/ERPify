<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that every active session of a user was revoked EXCEPT the one in hand ("sign out my other devices").
 * A single coarse fact whose subject is the user, so the aggregateId is the `userId` — the bulk path revokes rows
 * with a directed UPDATE and never loads each aggregate, so it cannot emit a per-session {@see SessionRevoked}.
 * The payload carries the `keptSessionId` (the current session, never self-expelled) so a consumer can tell this
 * apart from a full {@see AllSessionsRevoked}: a "force re-login everywhere" reactor must not evict the kept
 * session. It has no consumer yet, so it is emitted to the outbox but wired to no transport.
 */
final class OtherSessionsRevoked extends DomainEvent
{
    public function __construct(
        string $aggregateId,
        private readonly string $keptSessionId,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn);
    }

    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.session.others-revoked';
    }

    #[Override]
    public static function aggregateType(): string
    {
        return 'Iam.Session';
    }

    /**
     * @return array{keptSessionId: string}
     */
    #[Override]
    public function toPrimitives(): array
    {
        return ['keptSessionId' => $this->keptSessionId];
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
        $keptSessionId = self::stringMember($body, 'keptSessionId');

        return new self($aggregateId, $keptSessionId, $eventId, new DateTimeImmutable($occurredOn));
    }

    public function keptSessionId(): string
    {
        return $this->keptSessionId;
    }
}
