<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Application;

use Erpify\Shared\Images\Domain\DerivativeOrigin;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;

/**
 * What an owner may ask about the derivatives that carry its origins, without queueing anything: whether one origin
 * still holds any, and which of its origins images still derive from. Reads only; releasing is
 * {@see ReleaseDerivatives}' job, so a question asked on every tick costs a query and never a deletion request.
 */
final readonly class DerivativeOrigins
{
    public function __construct(
        private ImageRepository $imageRepository,
    ) {
    }

    public function holdsAny(DerivativeOrigin $origin): bool
    {
        return [] !== $this->imageRepository->idsByOrigin($origin, 1);
    }

    /**
     * @return list<string> origin ids of type `$type` that images still derive from, ascending after `$afterId`
     */
    public function idsOfType(string $type, int $limit, ?string $afterId = null): array
    {
        return $this->imageRepository->originIdsOfType($type, $limit, $afterId);
    }
}
