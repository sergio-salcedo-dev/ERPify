<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Event;

use DateTimeImmutable;
use Override;

/**
 * Shared envelope for the six {@see \Erpify\Iam\Invitation\Domain\Entity\Invitation} events: the subject of
 * every one of them is the invited user, so the `invitedUserId` IS the envelope's `aggregateId` and the payload
 * is empty — no email, no token, and above all not the invitation id.
 *
 * **The invitation id is deliberately absent from the envelope, and that is a security invariant rather than a
 * modelling preference.** It is the selector half of the `<invitationId>.<secret>` acceptance link and it keys
 * the per-selector accept budget. What keeping it here costs is **permanence**: `event_store` has no TTL, it is
 * a designated export surface, and its one sanctioned mutation erases by a person's id — which a selector is
 * not — so an envelope carrying it would hold that selector long after the invitation row, and the person, were
 * gone. **It does not, on its own, close the drain against a reader of the live database**: the same value is
 * the invitation's primary key, so `SELECT id FROM iam_invitation WHERE status = 'SENT'` reaches every live
 * selector regardless of what this envelope holds. Closing that would take a selector minted apart from the
 * aggregate id, which is a larger change and a separate decision. The sibling reset flow reaches the same shape
 * from the same reasoning ({@see \Erpify\Iam\Identity\Domain\Event\PasswordResetRequested}).
 *
 * `aggregateType()` stays `Iam.Invitation` while the id denotes a person: `aggregate_type` names the lifecycle
 * family and `aggregate_id` names the subject, so the pair `event_store_aggregate_idx` indexes stays precise
 * and the invitation family remains filterable. It is also why `api/.persistent-transport-policy` classifies
 * this type as `person` — that registry judges what the `aggregate_id` denotes, never the type's name.
 *
 * Six near-identical shapes past the Rule of Three earn one trait so their envelope contract cannot diverge by
 * copy-paste; kept PER MODULE (never unified with Session/Identity snapshots — see the
 * `bankaccount-event-envelope-trait-vs-bank` decision). Each event class only declares its own
 * {@see eventName()}; the constructor, the stream family, the schema version and the primitives round-trip live
 * here. `self` inside a trait resolves to the using class, so {@see fromPrimitives()} rebuilds the concrete
 * event.
 */
trait CarriesInvitationSnapshot
{
    public function __construct(
        string $invitedUserId,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
    ) {
        parent::__construct($invitedUserId, $eventId, $occurredOn);
    }

    #[Override]
    public static function aggregateType(): string
    {
        return 'Iam.Invitation';
    }

    /**
     * v1 of this envelope was keyed by the invitation id and carried the `invitedUserId` in its payload; this is
     * v2. A stored v1 row therefore resolves no registered class in
     * {@see \Erpify\Shared\Event\Infrastructure\Mapper\ReflectionDomainEventMapper} and fails loudly on read,
     * which is the intended outcome: its `aggregate_id` means something this class no longer means, and no
     * upcaster can repair that — an upcaster transforms the payload and never sees the aggregate column. **The
     * loudness is an uncaught `InvalidArgumentException` in the projection runner**, so if a projector ever
     * subscribes to these names, un-migrated rows must be dealt with first; the blast radius is recorded in
     * `docs/adr/event-store-and-projections.md` D12.
     */
    #[Override]
    public static function eventVersion(): int
    {
        return 2;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toPrimitives(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") the signature is mandated by the abstract
     *                                                  `DomainEvent::fromPrimitives()`, and `$body` is empty at
     *                                                  this schema version by design: the whole subject of these
     *                                                  events is the aggregate id.
     */
    #[Override]
    public static function fromPrimitives(
        string $aggregateId,
        array $body,
        string $eventId,
        string $occurredOn,
    ): static {
        return new self($aggregateId, $eventId, new DateTimeImmutable($occurredOn));
    }

    public function invitedUserId(): string
    {
        return $this->aggregateId();
    }
}
