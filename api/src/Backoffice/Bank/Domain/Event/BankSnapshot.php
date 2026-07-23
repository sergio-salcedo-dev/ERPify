<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Event;

/**
 * Immutable payload shared by the bank lifecycle events that carry a full snapshot (created, updated).
 * It owns the serialization contract — {@see toPrimitives()} / {@see fromPrimitives()} — so those two
 * events stay byte-identical in the reproducible event store by composing the same value object rather
 * than inheriting a common supertype. The aggregate id and envelope (eventId/occurredOn) live on the
 * event row, never here.
 */
final readonly class BankSnapshot
{
    public function __construct(
        public string $name,
        public string $shortName,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toPrimitives(): array
    {
        return [
            'name' => $this->name,
            'shortName' => $this->shortName,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromPrimitives(array $body): self
    {
        return new self(
            self::stringField($body, 'name'),
            self::stringField($body, 'shortName'),
            self::stringField($body, 'createdAt'),
            self::stringField($body, 'updatedAt'),
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
}
