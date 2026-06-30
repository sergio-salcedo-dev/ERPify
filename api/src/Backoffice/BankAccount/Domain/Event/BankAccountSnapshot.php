<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Event;

/**
 * Immutable payload shared by the bank-account lifecycle events (created, updated, deleted). It owns
 * the serialization contract — {@see toPrimitives()} / {@see fromPrimitives()} — so the events stay
 * byte-identical in the reproducible event store by composing the same value object rather than
 * inheriting a common supertype. The aggregate id and envelope (eventId/occurredOn) live on the event
 * row, never here.
 *
 * Deliberately PII-free: it carries the owning `bankId` (the realtime collection topic is per-bank),
 * the `status` and the timestamps — never the IBAN, holder name, BIC or alias. The detail is fetched
 * by the client through the authenticable read; the broadcast only signals "refetch".
 */
final readonly class BankAccountSnapshot
{
    public function __construct(
        public string $bankId,
        public string $status,
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
            'bankId' => $this->bankId,
            'status' => $this->status,
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
            self::stringField($body, 'bankId'),
            self::stringField($body, 'status'),
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
