<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Repository\LiveIdentityDirectory;
use Erpify\Shared\Audit\Application\PersonResourceReferences;
use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;

/**
 * Detective control over the places `api/.person-reference-policy` classifies as holding a person's
 * identifier, plus the audit trail's resource axis: it surfaces the ids those places still hold although no
 * live identity backs them.
 *
 * That scope is narrower than "everywhere a person id is persisted", and the difference must not be blurred:
 * `audit_log.actor_id`, `audit_log.metadata` and `event_store.aggregate_id` also hold person ids, none of
 * them has a Doctrine entity, and none is reachable from here. The registry's blind-spot block names all
 * three; only the resource axis is in this control, and only because a control of its own already exists for
 * it (`api/.audit-resource-types`).
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
 * Liveness is resolved ONCE, over the union of every place's ids, rather than per place. Two reasons, and
 * the first is correctness: with a probe per place, an erasure committing mid-run is seen by the places
 * probed after it and not by those probed before, so one report could name a subject under one axis and
 * clear them under another — a verdict true of no single state of the database. One probe also collapses
 * five expanded `IN` lists into one over the deduplicated union, which matters because a person typically
 * appears in several of these places at once.
 *
 * The reads are still NOT one snapshot: each source queries its own table before the union probe runs, so an
 * erasure that commits inside that window can be reported as a divergence it is not. It self-corrects on the
 * next run and the suggested repair is idempotent, so the cost is a transient false positive rather than a
 * wrong action — but it is a real limit, and the registry's blind-spot block states it.
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
        $places = $this->placesHoldingPersonIds();
        $live = $this->identities->existingIdsAmong($this->distinctIdsAcross($places));
        $verdict = UnreconciledPersonReferences::none();

        foreach ($places as $place) {
            $verdict = $verdict->withAxis(
                $place['axis'],
                \array_values(\array_diff($place['ids'], $live)),
            );
        }

        return $verdict;
    }

    /**
     * @return non-empty-list<array{axis: PersonReferenceAxis, ids: list<string>}>
     */
    private function placesHoldingPersonIds(): array
    {
        $places = [[
            'axis' => PersonReferenceAxis::of(self::AUDIT_RESOURCE_AXIS),
            // The port returns only rows still holding the real id: an anonymised reference carries a
            // pseudonym that resolves to no live identity by design, so including those would report every
            // correct erasure as a divergence. The other places have no equivalent — there the row is
            // deleted rather than pseudonymised, so any surviving id that does not resolve IS the divergence.
            'ids' => $this->distinct($this->auditReferences->unerasedIdsOfType(
                FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE,
            )),
        ]];

        foreach ($this->personReferenceSources as $personReferenceSource) {
            $places[] = [
                'axis' => $personReferenceSource->axis(),
                'ids' => $this->distinct($personReferenceSource->retainedPersonIds()),
            ];
        }

        return $places;
    }

    /**
     * @param non-empty-list<array{axis: PersonReferenceAxis, ids: list<string>}> $places
     *
     * @return list<string>
     */
    private function distinctIdsAcross(array $places): array
    {
        return $this->distinct(\array_merge(...\array_column($places, 'ids')));
    }

    /**
     * Deduplicates rather than trusting: every port here promises a distinct list and every implementation
     * says `DISTINCT`, but nothing enforces it, and `array_diff` keeps duplicates in its left operand — so
     * one forgetful source would report a person once per row, which is the noise those promises exist to
     * prevent. It also shrinks the union probe for the common case of one person held in several places.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function distinct(array $ids): array
    {
        return \array_values(\array_unique($ids));
    }
}
