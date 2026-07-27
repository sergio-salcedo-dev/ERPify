<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

/**
 * Read side of the resource-axis erasure: the still-unerased references the trail holds for one
 * person-denoting resource type.
 *
 * It exists because that erasure is driven by the bounded context that owns the person
 * (`docs/adr/audit-activity-log.md` D4), which makes it a distributed obligation — and a distributed
 * obligation needs a detective control, exactly as crypto-shredding has {@see SubjectErasureReconciler}.
 * The owning context asks for the ids the trail still names, resolves which of them no longer exist as
 * live subjects, and surfaces the difference: a subject erased everywhere except here.
 *
 * The split is deliberate. This module can list the ids; it cannot know whether an id is still a live
 * person, because that table belongs to the owning context. Answering both halves here would be the
 * knowledge inversion D4 exists to prevent — and would need a cross-context read this module must not make.
 *
 * Only rows with `resource_erased = FALSE` are returned: an already-anonymised reference carries a
 * pseudonym that resolves to nothing by design, so without that filter every correct erasure would report
 * as a divergence and the control would be pure noise.
 */
interface PersonResourceReferences
{
    /**
     * @return list<string> distinct `resource_id`s of not-yet-erased rows of this type (empty when none)
     */
    public function unerasedIdsOfType(string $resourceType): array;
}
