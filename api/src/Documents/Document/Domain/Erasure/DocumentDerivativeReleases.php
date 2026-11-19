<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Erasure;

/**
 * The release of an erased document's derivatives, on `document`: which rows still owe a release, when one was last
 * requested, and when a re-query confirmed none is left. Apart from {@see DocumentErasures}, whose statements are the
 * erasure itself, because this runs after it, on its own schedule, and decides nothing about the erasure.
 */
interface DocumentDerivativeReleases
{
    /**
     * Ids of `Erased` rows whose derivatives have not been confirmed released and whose last request, if any, is
     * older than `$rerequestAfterSeconds`, ascending after `$afterId` when given. A candidate list: the release sweep
     * asks the images module about each; a row it just asked about drops out until the interval passes, so rows that
     * stay pending never crowd the later ones out of a tick.
     *
     * @return list<string>
     */
    public function awaitingDerivativeRelease(
        int $rerequestAfterSeconds,
        int $limit,
        ?string $afterId = null,
    ): array;

    /**
     * Records that the release of the row's derivatives was requested now.
     */
    public function markDerivativesRequested(string $documentId): bool;

    /**
     * Records that no image derives from the row any more. Only an `Erased` row whose marker is still empty takes it,
     * so a repeat reports false and changes nothing.
     */
    public function markDerivativesReleased(string $documentId): bool;

    /**
     * Ids of `Erased` rows whose derivatives are still not confirmed released although the erasure was recorded at
     * least `$olderThanSeconds` ago: legible renderings of a document the system said it erased.
     *
     * @return list<string>
     */
    public function derivativesOverdue(int $olderThanSeconds, int $limit): array;
}
