<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

use BackedEnum;
use DateTimeInterface;
use UnitEnum;

/**
 * Turns a Doctrine field changeset (`field => [old, new]`) into the structured `metadata` diff a
 * change-level audit row carries — the "from what value to what value" an ISO 27001 review asks for. Pure
 * transformation: no ORM, no I/O, so the change shape is unit-testable in isolation from the `onFlush`
 * listener that feeds it.
 *
 * Every value is coerced to a JSON-safe scalar so the diff round-trips through the JSONB column without
 * surprises: scalars and null pass through, a date becomes an ISO-8601 string, a backed enum its backing
 * value and a pure enum its case name, and any other object is reduced to its type name so opaque object
 * state never leaks into the trail. A change that is not a simple `[old, new]` pair — Doctrine reports a
 * to-many change as the collection itself — is skipped: field-level scalar capture is the scope here.
 */
final class AuditChangeDiff
{
    /**
     * @param array<string, mixed> $changeSet
     *
     * @return array{changes: array<string, array{old: scalar|null, new: scalar|null}>}
     */
    public function of(array $changeSet): array
    {
        $changes = [];

        foreach ($changeSet as $field => $change) {
            if (!\is_array($change)) {
                continue;
            }

            if (!\array_key_exists(0, $change)) {
                continue;
            }

            if (!\array_key_exists(1, $change)) {
                continue;
            }

            $changes[$field] = ['old' => $this->scalarize($change[0]), 'new' => $this->scalarize($change[1])];
        }

        return ['changes' => $changes];
    }

    private function scalarize(mixed $value): bool|float|int|string|null
    {
        return match (true) {
            null === $value, \is_scalar($value) => $value,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            default => \get_debug_type($value),
        };
    }
}
