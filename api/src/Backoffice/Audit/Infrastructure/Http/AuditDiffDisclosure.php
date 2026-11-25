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
            $disclosed[$field] = $this->mayServe((string) $field, $pair, $clear)
                ? $pair
                : ['old' => AuditEventDetailResourceMapper::WITHHELD, 'new' => AuditEventDetailResourceMapper::WITHHELD];
        }

        return $disclosed;
    }

    /**
     * @param list<string> $clear
     */
    private function mayServe(string $field, mixed $pair, array $clear): bool
    {
        if (!\is_array($pair) || !\array_key_exists('old', $pair) || !\array_key_exists('new', $pair)) {
            return false;
        }

        return \in_array($field, $clear, true) || ($this->isSealedSide($pair['old']) && $this->isSealedSide($pair['new']));
    }

    /**
     * A side that is absent, or the envelope a sealed value travels in and nothing else: an array carrying any other
     * key beside it is a map somebody built, and is not served on the strength of containing the marker.
     */
    private function isSealedSide(mixed $side): bool
    {
        return null === $side
            || (\is_array($side) && [AuditMetadataShape::SEALED_VALUE_KEY] === \array_keys($side));
    }
}
