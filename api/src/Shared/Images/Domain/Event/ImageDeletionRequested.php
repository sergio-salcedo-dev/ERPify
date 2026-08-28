<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * A consuming context no longer needs an image and asks for its bytes to be released.
 *
 * Named as a REQUEST rather than as a fact, and the distinction is the contract. `ImageDeleted` would
 * assert something that has not happened when the message is published: the publisher decides it no longer
 * needs the image, and this module is what then removes the bytes. A reader who takes the name literally
 * builds on a deletion that may still be queued, may be retrying, or may have failed permanently.
 *
 * **The payload is empty on purpose.** Only the identifier travels, as the envelope's aggregate id — never
 * a storage key, a path, a filename, a digest or an absolute path. Anything added here is retained by two
 * sinks no erasure path reaches: the `messenger_messages` row while it is queued or dead-lettered, and the
 * `event_store` row for ever.
 *
 * Delivery is at-least-once, so the handler must be idempotent and must tolerate two workers racing on the
 * same message. It carries no ordering guarantee and no exactly-once guarantee; the structural template it
 * borrows from an aggregate-creation event shares none of those properties, only the shape.
 */
final class ImageDeletionRequested extends DomainEvent
{
    public function __construct(
        string $aggregateId,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn);
    }

    #[Override]
    public static function eventName(): string
    {
        return 'erpify.shared.image.deletion_requested';
    }

    #[Override]
    public static function aggregateType(): string
    {
        return 'Shared.Image';
    }

    /**
     * @return array<string, string|null>
     */
    #[Override]
    public function toPrimitives(): array
    {
        return [];
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
        return new self($aggregateId, $eventId, new DateTimeImmutable($occurredOn));
    }
}
