<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Repository\LiveIdentityDirectory;
use Erpify\Shared\Audit\Application\PersonResourceReferences;
use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;

/**
 * Detective control over every place a person's identifier is persisted: surfaces the ids those places still
 * hold although no live identity backs them.
 *
 * Erasing each of those places is a distributed obligation — nothing in the schema references
 * `identity_user`, so deleting an identity cascades nowhere and every reference owes its removal to a use
 * case ({@see FulfilIdentityErasure} chains them; `api/.person-reference-policy` is the inventory). An
 * obligation discharged by remembering is one a future caller — a second erasure path, a manual repair, a
 * migration — can skip without anything failing at the time. The gate over the registry proves the erasure
 * is WRITTEN; only this proves the row is GONE. It is the same answer the repo already gives for
 * crypto-shredding, whose destroyed keys are reconciled against their evidence rather than made impossible
 * to lose: divergence surfaced beats divergence assumed away.
 *
 * The verdict is a plain difference of two facts, and neither side may hold both: each owning context lists
 * the ids it stores, this context resolves which of them are no longer live identities. A dangling id is a
 * missed erasure because {@see EraseIdentitySubject} hard-deletes — an erased identity leaves no row behind,
 * so "held elsewhere, absent from `identity_user`" has exactly one cause.
 *
 * Three collaborators, and the asymmetry between the first two is the design rather than an accident. The
 * registry-backed places arrive as a tagged collection, so a context that comes to hold a person's id joins
 * this control by implementing the contract; that collection is what the completeness gate enumerates
 * against the registry. The audit trail is a collaborator of its own because its column can never be a
 * registry key: `audit_log` has no Doctrine entity, so no property declares `resource_id` and the registry's
 * reflection sweep is structurally blind to it. Folding it into the collection would make the gate's
 * "every declared key has a registry line" direction red by construction.
 *
 * It reads only. Repairing is a deliberate operator act through the erasure use case, not something a
 * scheduled check does to a compliance table on its own.
 */
final readonly class ReconcileErasedSubjectReferences
{
    /**
     * The audit trail's resource axis, named by its column because no registry key can name it. It is not a
     * spelling anybody may reuse: it is the one place in this control whose identity does not come from
     * `api/.person-reference-policy`, and the reason is stated among that file's blind spots.
     */
    private const string AUDIT_RESOURCE_AXIS = 'audit_log.resource_id';

    /**
     * @param iterable<PersonReferenceSource> $personReferenceSources
     */
    public function __construct(
        private PersonResourceReferences $auditReferences,
        private iterable $personReferenceSources,
        private LiveIdentityDirectory $identities,
    ) {
    }

    public function unreconciledReferences(): UnreconciledPersonReferences
    {
        $verdict = UnreconciledPersonReferences::none()->withAxis(
            PersonReferenceAxis::of(self::AUDIT_RESOURCE_AXIS),
            // The port returns only rows still holding the real id: an anonymised reference carries a
            // pseudonym that resolves to no live identity by design, so including those would report every
            // correct erasure as a divergence. The other places have no equivalent — there the row is
            // deleted rather than pseudonymised, so any surviving id that does not resolve IS the divergence.
            $this->danglingAmong($this->auditReferences->unerasedIdsOfType(
                FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE,
            )),
        );

        foreach ($this->personReferenceSources as $personReferenceSource) {
            $verdict = $verdict->withAxis(
                $personReferenceSource->axis(),
                $this->danglingAmong($personReferenceSource->retainedPersonIds()),
            );
        }

        return $verdict;
    }

    /**
     * @param list<string> $referencedIds
     *
     * @return list<string>
     */
    private function danglingAmong(array $referencedIds): array
    {
        $live = $this->identities->existingIdsAmong($referencedIds);

        return \array_values(\array_diff($referencedIds, $live));
    }
}
