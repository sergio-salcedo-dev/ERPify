<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Infrastructure\Http;

use Erpify\Shared\Audit\Domain\AuditMetadataShape;

/**
 * Decides, field by field, which parts of a `change` diff the detail route may serve in clear.
 *
 * A personal field of an audited aggregate is sealed before it reaches the row (`PiiDiffSealer`), and a sealed
 * value is safe to hand out as such: the client shows it as sealed and the plaintext dies with the subject's key.
 * What the write side cannot promise is that every OTHER value in the map is not personal — a row written before
 * the sealing existed, a field nobody classified, a row inserted straight into the table — and nothing on the
 * erasure path rewrites `metadata`, so whatever is served in clear outlives the person it names. So the read side
 * is closed by default: a field is served only when it is a sealed envelope, or when the registry below names it
 * as a clear (non-personal) field of that resource type. Anything else is withheld, with the diff's SHAPE kept
 * (`old` and `new` both present) so the client still renders a field that changed instead of refusing the event.
 *
 * The registry holds STRINGS, not classes: the audit module may not import the modules it records, and a
 * resource type is a compliance contract that stays stable across class refactors. What keeps it honest is
 * `AuditDiffDisclosureRegistryTest`, which derives each type's clear fields from the entity's own ORM mapping and
 * its `#[PersonalData]` classification and requires the two to be equal — so a new column, or a column that
 * becomes personal, reds a test instead of being served by accident. Adding an audited aggregate adds a line here.
 */
final readonly class AuditDiffDisclosure
{
    /** base64url without padding, at least a 24-byte nonce plus a 16-byte tag. */
    private const string SEALED_ENVELOPE_PATTERN = '/^[A-Za-z0-9_-]{54,}$/D';

    /**
     * @var array<string, list<string>> resource type => the fields of that type that carry no personal data
     */
    public const array CLEAR_FIELDS = [
        'Bank' => ['id', 'name', 'nameNormalized', 'shortName', 'logoImageId', 'createdAt', 'updatedAt'],
        'BankAccount' => ['id', 'bankId', 'bic', 'currency', 'status', 'createdAt', 'updatedAt'],
    ];

    /**
     * @param array<array-key, mixed> $changes a decoded, non-empty map read off a `change` row
     *
     * @return array<array-key, mixed>
     */
    public function disclose(?string $resourceType, array $changes): array
    {
        $clear = null === $resourceType ? [] : (self::CLEAR_FIELDS[$resourceType] ?? []);
        $disclosed = [];

        foreach ($changes as $field => $pair) {
            $disclosed[$field] = $this->mayServe((string) $field, $pair, $clear) ? $pair : $this->withheldPair();
        }

        return $disclosed;
    }

    /**
     * @return array{old: string, new: string}
     */
    private function withheldPair(): array
    {
        return ['old' => AuditEventDetailResourceMapper::WITHHELD, 'new' => AuditEventDetailResourceMapper::WITHHELD];
    }

    /**
     * @param list<string> $clear
     */
    private function mayServe(string $field, mixed $pair, array $clear): bool
    {
        // Exactly the two sides the diff builder writes: a pair carrying anything else is not one it produced.
        if (!\is_array($pair) || ['new', 'old'] !== $this->sortedKeys($pair)) {
            return false;
        }

        // Both keys are present: the check above admits no other key set.
        $old = $pair['old'] ?? null;
        $new = $pair['new'] ?? null;

        if (\in_array($field, $clear, true)) {
            return $this->isScalarOrNull($old) && $this->isScalarOrNull($new);
        }

        return $this->isSealedSide($old) && $this->isSealedSide($new);
    }

    /**
     * A side that is absent, or the envelope a sealed value travels in and nothing else, holding what a sealed value
     * looks like: the writer's base64url (no padding) of a 24-byte nonce, the ciphertext and a 16-byte tag, so at
     * least 54 characters of that alphabet. A shape test and not a proof — it refuses an address, a name or a nested
     * map put under the marker, and cannot tell a long plaintext token from a ciphertext — but an array carrying
     * any other key beside the marker is a map somebody built and is not served on the strength of containing it.
     */
    private function isSealedSide(mixed $side): bool
    {
        if (null === $side) {
            return true;
        }

        if (!\is_array($side) || [AuditMetadataShape::SEALED_VALUE_KEY] !== \array_keys($side)) {
            return false;
        }

        $envelope = $side[AuditMetadataShape::SEALED_VALUE_KEY] ?? null;

        return \is_string($envelope) && 1 === \preg_match(self::SEALED_ENVELOPE_PATTERN, $envelope);
    }

    private function isScalarOrNull(mixed $value): bool
    {
        return null === $value || \is_scalar($value);
    }

    /**
     * @param array<array-key, mixed> $pair
     *
     * @return list<array-key>
     */
    private function sortedKeys(array $pair): array
    {
        $keys = \array_keys($pair);
        \sort($keys);

        return $keys;
    }
}
