<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Shared payload and serialization for bank lifecycle events that carry the full bank snapshot.
 * Concrete subclasses differ only in {@see eventName()}. The aggregate id lives on the envelope
 * (it is {@see aggregateId()}, not payload), so it is no longer part of {@see toPrimitives()}.
 *
 * @phpstan-consistent-constructor
 */
abstract class AbstractBankChangedDomainEvent extends DomainEvent
{
    public function __construct(
        string $bankId,
        private readonly string $name,
        private readonly string $shortName,
        private readonly string $createdAt,
        private readonly string $updatedAt,
        private readonly ?string $logoMediaId = null,
        private readonly ?string $logoContentHash = null,
        private readonly ?string $storedObjectContentHash = null,
        private readonly ?string $storedObjectMimeType = null,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
    ) {
        parent::__construct($bankId, $eventId, $occurredOn);
    }

    #[Override]
    public static function aggregateType(): string
    {
        return 'Backoffice.Bank';
    }

    /**
     * @return array<string, string|null>
     */
    #[Override]
    public function toPrimitives(): array
    {
        return [
            'name' => $this->name,
            'shortName' => $this->shortName,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'logoMediaId' => $this->logoMediaId,
            'logoContentHash' => $this->logoContentHash,
            'storedObjectContentHash' => $this->storedObjectContentHash,
            'storedObjectMimeType' => $this->storedObjectMimeType,
        ];
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
        return new static(
            $aggregateId,
            self::stringField($body, 'name'),
            self::stringField($body, 'shortName'),
            self::stringField($body, 'createdAt'),
            self::stringField($body, 'updatedAt'),
            self::nullableStringField($body, 'logoMediaId'),
            self::nullableStringField($body, 'logoContentHash'),
            self::nullableStringField($body, 'storedObjectContentHash'),
            self::nullableStringField($body, 'storedObjectMimeType'),
            $eventId,
            new DateTimeImmutable($occurredOn),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? null;

        return \is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function nullableStringField(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;

        return \is_string($value) ? $value : null;
    }
}
