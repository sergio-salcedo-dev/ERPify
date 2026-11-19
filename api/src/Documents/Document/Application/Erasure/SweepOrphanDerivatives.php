<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

use Erpify\Documents\Document\Application\Derivative\DocumentDerivativeOrigin;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Erasure\DocumentDerivativeReleases;
use Erpify\Shared\Images\Application\DerivativeOrigins;
use Erpify\Shared\Images\Application\ReleaseDerivatives;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Walks the origins images still derive from, rather than this context's rows, and releases the derivatives no live
 * document accounts for: an origin with no document row, an `Erased` document whose release was already confirmed, or
 * a document in any state but `Ready` or `Erased`. {@see ReleaseErasedDerivatives} only sees documents, so it can never
 * find a derivative whose document row is gone, nor one a restore brought back after its release was confirmed; this
 * pass is what finds them. An `Erased` row still awaiting its release is left to that sweep.
 *
 * Every origin it releases is an anomaly — no ordinary path leaves one — so finding any is one `error` line with the
 * count and up to twenty document ids. It runs on the re-request interval, so a request it queued is not repeated
 * while the transport may still be retrying it.
 */
final readonly class SweepOrphanDerivatives
{
    private const int IDS_PER_LINE = 20;

    public function __construct(
        private DerivativeOrigins $derivativeOrigins,
        private DocumentDerivativeReleases $releases,
        private ReleaseDerivatives $releaseDerivatives,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int how many orphaned origins this run released
     */
    public function run(int $limit): int
    {
        $orphans = [];
        $failures = 0;
        $after = null;

        do {
            $page = $this->derivativeOrigins->idsOfType(DocumentDerivativeOrigin::TYPE, $limit, $after);
            $states = $this->releases->statesOf($page);

            foreach ($page as $documentId) {
                $after = $documentId;

                if (!$this->isOrphan($documentId, $states[$documentId] ?? null)) {
                    continue;
                }

                try {
                    $this->releaseDerivatives->of(DocumentDerivativeOrigin::of($documentId));
                    $orphans[] = $documentId;
                } catch (Throwable $throwable) {
                    ++$failures;
                    $this->logger->error('The orphaned derivatives of a document could not be released.', [
                        'documentId' => $documentId,
                        'exceptionClass' => $throwable::class,
                    ]);
                }
            }
        } while (\count($page) === $limit);

        if ([] !== $orphans || 0 !== $failures) {
            $this->logger->error('Derivatives were found that no live document accounts for.', [
                'count' => \count($orphans) + $failures,
                'released' => \count($orphans),
                'documentIds' => \array_slice($orphans, 0, self::IDS_PER_LINE),
            ]);
        }

        return \count($orphans);
    }

    private function isOrphan(string $documentId, ?string $state): bool
    {
        if (null === $state) {
            return true;
        }

        if (DocumentState::Ready->value === $state) {
            return false;
        }

        if (DocumentState::Erased->value !== $state) {
            return true;
        }

        return $this->releases->releaseConfirmed($documentId);
    }
}
