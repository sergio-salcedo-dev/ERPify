<?php

declare(strict_types=1);

namespace Erpify\Shared\Privacy\Application;

use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;

/**
 * Read side of one person-reference axis: the person identifiers a context still holds in the column it
 * owns, and which column that is.
 *
 * The detective control over those columns is a difference of two facts, and neither side may hold both.
 * The context that owns the column can list the identifiers it stores; it cannot know whether an identifier
 * is still a live person, because that table belongs to the context that owns people. Answering both halves
 * in one place would be the cross-context read the architecture forbids — so each owning context implements
 * this, and the person's context resolves which of the listed ids are gone.
 *
 * The contract lives in the shared kernel rather than in the consuming context precisely so that no import
 * crosses a boundary: the reconciler depends on `Erpify\Shared\Privacy\…`, each implementation depends on
 * that same contract and on its own repository, and none of them names another context at all. `Shared` does
 * not learn what a membership is; it learns that a source of person references exists, which is privacy
 * vocabulary.
 *
 * Implementations are collected into the reconciler by the `erpify.person_reference_source` tag
 * (`config/services.yaml`), so a new owning context joins the control by existing rather than by editing it.
 * Two rules bind every implementation under `src/`:
 *
 *   - {@see axis()} must be a CONSTANT fact of the class, readable without a database. The coverage gate
 *     reads it by reflection over the source tree — it constructs the implementation without its
 *     constructor — so an axis derived from injected state breaks that read loudly rather than leaving the
 *     column silently unenumerated. (A test double is outside that sweep and may take its axis as an
 *     argument; nothing reads doubles against the registry.)
 *   - {@see retainedPersonIds()} returns IDS, never rows. A source that returned aggregates would drag
 *     `iam_session`'s `ip`/`device` or `iam_invitation`'s credential digest into an operator's console the
 *     first time the control reported anything.
 */
interface PersonReferenceSource
{
    /**
     * The column this source covers, keyed as `api/.person-reference-policy` classifies it.
     */
    public function axis(): PersonReferenceAxis;

    /**
     * Every person identifier the column currently holds, distinct and ordered.
     *
     * Both are load-bearing rather than cosmetic. Without `DISTINCT` a person named by a hundred rows is
     * reported a hundred times, and without a total order two consecutive runs over an unchanged table print
     * the same set differently, so any alert that diffs this control's output fires on noise.
     *
     * @return list<string> empty when the column holds none
     */
    public function retainedPersonIds(): array;
}
