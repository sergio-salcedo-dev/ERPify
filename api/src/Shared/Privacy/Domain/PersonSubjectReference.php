<?php

declare(strict_types=1);

namespace Erpify\Shared\Privacy\Domain;

use Attribute;

/**
 * Passive marker: the property it decorates persists the identifier of a natural person, and names the file
 * that erases it when that person exercises their right to erasure.
 *
 * Sibling of {@see PersonalData}, deliberately not the same attribute. That one is a contract of
 * TREATMENT — infrastructure reads it to decide encrypt-vs-clear per column — and this one is a contract of
 * REFERENCE: who is obliged to remove the identifier. Marking a foreign key as personal data would file it
 * under the encryption scope of the aggregate that owns the ROW, so the id would be destroyed with that
 * aggregate and survive the erasure of the person it names; and the treatment port answers in property
 * names, with nowhere to carry an owner.
 *
 * The owner travels as a path relative to `api/`, not a `::class` reference. The erasing use case routinely
 * belongs to a different bounded context from the entity holding the reference — a `Session` is erased from
 * `Iam\Identity` — so a class reference out of `Domain/` would be exactly the cross-context import the
 * architecture gates reject.
 *
 * Carries no behaviour. `api/.person-reference-policy` is the reviewable inventory of every persisted
 * identifier column; the build gate reads both and fails when a declaration here disagrees with it.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class PersonSubjectReference
{
    public function __construct(public string $erasedBy)
    {
    }
}
