<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Event;

use DateTimeImmutable;
use Override;

/**
 * Shared envelope for the six {@see \Erpify\Iam\Invitation\Domain\Entity\Invitation} events: they all carry the
 * same PII-free snapshot — the `invitedUserId` (a UUID, never the email or the token). The invitation id is the
 * envelope's `aggregateId`. Six near-identical shapes past the Rule of Three earn one trait so their payload
 * contract cannot diverge by copy-paste; kept PER MODULE (never unified with Session/Identity snapshots — see
 * the `bankaccount-event-envelope-trait-vs-bank` decision).
 *
 * Each event class only declares its own {@see eventName()}; the constructor, the stream family, and the
 * primitives round-trip live here. `self` inside a trait resolves to the using class, so {@see fromPrimitives()}
 * rebuilds the concrete event.
 */
trait CarriesInvitationSnapshot
{
    public function __construct(
        string $aggregateId,
        private readonly string $invitedUserId,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn);
    }

    #[Override]
    public static function aggregateType(): string
    {
        return 'Iam.Invitation';
    }

    /**
     * @return array{invitedUserId: string}
     */
    #[Override]
    public function toPrimitives(): array
    {
        return ['invitedUserId' => $this->invitedUserId];
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
        $invitedUserId = $body['invitedUserId'] ?? null;
        \assert(\is_string($invitedUserId));

        return new self($aggregateId, $invitedUserId, $eventId, new DateTimeImmutable($occurredOn));
    }

    public function invitedUserId(): string
    {
        return $this->invitedUserId;
    }
}
