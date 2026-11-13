<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Scanning\DocumentScanLeases;
use Erpify\Documents\Document\Domain\Scanning\ReturnOutcome;
use Erpify\Documents\Document\Domain\Scanning\ScanClaim;
use Override;
use RuntimeException;

/**
 * The real compare-and-sets with one of them made to fail as a database that dropped mid-statement would: every
 * method delegates, and the one named in `$failing` throws instead. What a sweep does with a transition that raises
 * is the subject; the transitions themselves are proved against Postgres elsewhere.
 */
final readonly class FaultingScanLeases implements DocumentScanLeases
{
    public function __construct(
        private DocumentScanLeases $inner,
        private string $failing,
    ) {
    }

    #[Override]
    public function dueCandidates(int $limit): array
    {
        $this->guard(__FUNCTION__);

        return $this->inner->dueCandidates($limit);
    }

    #[Override]
    public function claim(string $documentId): ?ScanClaim
    {
        $this->guard(__FUNCTION__);

        return $this->inner->claim($documentId);
    }

    #[Override]
    public function markStreaming(string $documentId, int $epoch): bool
    {
        $this->guard(__FUNCTION__);

        return $this->inner->markStreaming($documentId, $epoch);
    }

    #[Override]
    public function heartbeat(string $documentId, int $epoch): bool
    {
        $this->guard(__FUNCTION__);

        return $this->inner->heartbeat($documentId, $epoch);
    }

    #[Override]
    public function settle(string $documentId, int $epoch, DocumentState $verdict): bool
    {
        $this->guard(__FUNCTION__);

        return $this->inner->settle($documentId, $epoch, $verdict);
    }

    #[Override]
    public function returnToReceived(
        string $documentId,
        int $epoch,
        bool $scannerDown,
        bool $onlyIfLeaseLapsed,
    ): ReturnOutcome {
        $this->guard(__FUNCTION__);

        return $this->inner->returnToReceived($documentId, $epoch, $scannerDown, $onlyIfLeaseLapsed);
    }

    #[Override]
    public function expiredLeases(int $limit): array
    {
        $this->guard(__FUNCTION__);

        return $this->inner->expiredLeases($limit);
    }

    #[Override]
    public function cappedCandidates(int $limit): array
    {
        $this->guard(__FUNCTION__);

        return $this->inner->cappedCandidates($limit);
    }

    #[Override]
    public function failCapped(string $documentId): bool
    {
        $this->guard(__FUNCTION__);

        return $this->inner->failCapped($documentId);
    }

    #[Override]
    public function reopen(string $documentId): bool
    {
        $this->guard(__FUNCTION__);

        return $this->inner->reopen($documentId);
    }

    private function guard(string $method): void
    {
        if ($method === $this->failing) {
            throw new RuntimeException('the database dropped during ' . $method);
        }
    }
}
