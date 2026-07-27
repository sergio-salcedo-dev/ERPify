<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Audit\Application\PersonResourceReferences;

/**
 * Detective control over the resource-axis erasure: surfaces identities the trail still names by their real
 * id although they no longer exist as live subjects.
 *
 * Erasing the resource axis is this context's obligation, discharged inside {@see FulfilIdentityErasure}
 * (`docs/adr/audit-activity-log.md` D4). That makes it something a future caller — a second erasure path, a
 * manual repair, a migration — can skip without anything failing at the time. This is the same answer the
 * repo already gives for crypto-shredding, whose destroyed keys are reconciled against their evidence
 * rather than made impossible to lose: divergence surfaced beats divergence assumed away.
 *
 * The verdict is a plain difference of two facts each side owns: the audit module lists the ids it still
 * names unerased, this context resolves which of them are no longer live users. A dangling id is a missed
 * erasure because {@see EraseIdentitySubject} hard-deletes — an erased identity leaves no row behind, so
 * "named in the trail, absent from `identity_user`" has exactly one cause.
 *
 * It reads only. Repairing is a deliberate operator act through the erasure use case, not something a
 * scheduled check does to a compliance table on its own.
 */
final readonly class ReconcileErasedSubjectReferences
{
    public function __construct(
        private PersonResourceReferences $references,
        private UserRepository $users,
    ) {
    }

    /**
     * @return list<string> subject ids the trail still names although the identity is gone (empty when
     *                      everything reconciles)
     */
    public function unreconciledSubjectIds(): array
    {
        $dangling = [];

        foreach ($this->references->unerasedIdsOfType(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE) as $id) {
            if (!$this->users->findById($id) instanceof User) {
                $dangling[] = $id;
            }
        }

        return $dangling;
    }
}
