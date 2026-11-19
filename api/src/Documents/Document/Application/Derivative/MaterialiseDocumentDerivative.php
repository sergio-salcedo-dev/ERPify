<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Derivative;

use Erpify\Shared\Images\Application\MaterialiseDerivative;
use Erpify\Shared\Images\Application\OriginGuard;
use Erpify\Shared\Images\Domain\Exception\DerivativeOriginNotLive;
use Erpify\Shared\Images\Domain\ImageId;
use SensitiveParameter;

/**
 * The seam a renderer of this context calls to keep a rendering of a document — a page preview, a thumbnail — as an
 * image that dies with it. It is the one way in, so the origin and the guard are this context's, never the caller's.
 *
 * Only a `Ready` document admits a derivative: its content has a clean verdict, and its erasure has not started. An
 * erasure that commits first refuses the derivative; one that commits after finds it through the release sweep.
 */
final readonly class MaterialiseDocumentDerivative
{
    public function __construct(
        private MaterialiseDerivative $materialiseDerivative,
        private OriginGuard $documentOriginGuard,
    ) {
    }

    /**
     * @throws DerivativeOriginNotLive when the document is absent, not `Ready`, or erased; nothing is kept
     */
    public function materialise(
        string $documentId,
        #[SensitiveParameter]
        string $bytes,
        ?string $declaredMediaType = null,
    ): ImageId {
        return $this->materialiseDerivative->materialise(
            DocumentDerivativeOrigin::of($documentId),
            $this->documentOriginGuard,
            $bytes,
            $declaredMediaType,
        );
    }
}
