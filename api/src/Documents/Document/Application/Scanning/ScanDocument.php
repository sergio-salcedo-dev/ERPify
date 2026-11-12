<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Scanning;

use Erpify\Documents\Document\Application\AppendCustody;
use Erpify\Documents\Document\Application\Upload\UploadEvidence;
use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Scanning\DocumentScanLeases;
use Erpify\Documents\Document\Domain\Scanning\MalwareScanner;
use Erpify\Documents\Document\Domain\Scanning\ReturnOutcome;
use Erpify\Documents\Document\Domain\Scanning\ScanClaim;
use Erpify\Documents\Document\Domain\Scanning\ScanInterrupted;
use Erpify\Documents\Document\Domain\Scanning\ScannerUnavailable;
use Erpify\Documents\Document\Domain\Scanning\ScanVerdict;
use Erpify\Documents\Document\Domain\ScanPolicy;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\DocumentStorageException;
use Erpify\Documents\Document\Domain\Storage\ObjectNotFound;
use Erpify\Shared\Crypto\Application\StreamEnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Domain\Exception\StreamTransferFailed;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Scans ONE document: claim it, unseal the original and stream the plaintext to the scanner while the lease is
 * heartbeaten, then post the verdict. Every transition is a compare-and-set of {@see DocumentScanLeases} and a
 * custody row travels with it in one transaction (the statement takes the row lock; {@see AppendCustody} takes it
 * again), so a lost compare-and-set writes no row.
 *
 * **How each ending is accounted** (the contract is `docs/adr/documents-custody-log.md` D8):
 *
 *  - a verdict (`Ready`, `Quarantined`) is posted, and so is `ScanFailed` for a deterministic failure — a failed
 *    authentication or a missing final chunk ({@see DecryptionFailed}), a missing final object, any scanner limit
 *    answer — with an alarm line and no retry. A limit answer is never `Ready`;
 *  - no verdict (the connect failed, the stream reset, a timeout, a transient local read error) returns the claim to
 *    `Received` and counts: an outage return when streaming had not begun or the run's single probe finds the
 *    scanner down, an attempt otherwise. At a cap the same statement ends the document in `ScanFailed`;
 *  - a superseded claim, an `Erased` row seen by a heartbeat, a verdict refused by the clock and
 *    {@see DekDestroyed} mid-scan end the PASS with nothing counted and the claim left live (this class releases
 *    nothing). If the lease then lapses on a row that is not `Erased`, {@see ExpireScanLeases} returns it under the
 *    accounting of a worker that died — an outage return when streaming had not begun, an attempt after — and a
 *    document that keeps ending this way reaches `ScanFailed` with its alarm. A row that is `Erased` by then
 *    matches no predicate and nothing is counted.
 *
 * The decrypted plaintext goes only to the scanner's socket; it is written to no file, and the partial output of a
 * failed `unseal` was never kept anywhere to discard.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the orchestrator of one claim's lifecycle
 */
final readonly class ScanDocument
{
    public function __construct(
        private DocumentScanLeases $leases,
        private DocumentRepository $documents,
        private DocumentStorage $storage,
        private StreamEnvelopeEncryptor $encryptor,
        private MalwareScanner $scanner,
        private AppendCustody $custody,
        private TransactionManager $transactionManager,
        private LoggerInterface $logger,
        private int $heartbeatIntervalSeconds = ScanPolicy::HEARTBEAT_SECONDS,
    ) {
    }

    public function scan(string $documentId, ScannerHealth $health): ScanResult
    {
        $claim = $this->claim($documentId);

        if (!$claim instanceof ScanClaim) {
            return ScanResult::NotClaimed;
        }

        $document = $this->documents->findById($documentId);

        if (!$document instanceof Document) {
            return ScanResult::Aborted;
        }

        $session = new ScanSession($this->leases, $documentId, $claim->epoch, $this->heartbeatIntervalSeconds);
        $sealed = null;

        try {
            $sealed = $this->storage->open($document->scopeId());

            $verdict = $this->scanner->scan(
                fn ($sink) => $this->unsealInto($document->scopeId(), $sealed, $session, $sink),
                $session->keepAlive(...),
            );
        } catch (Throwable $throwable) {
            return $this->afterFailure($throwable, $claim, $session, $health);
        } finally {
            if (\is_resource($sealed)) {
                \fclose($sealed);
            }
        }

        return $this->afterVerdict($verdict, $claim);
    }

    /**
     * @param resource $sealed
     * @param resource $sink
     */
    private function unsealInto(string $scopeId, $sealed, ScanSession $session, $sink): void
    {
        $plaintext = WriteCallbackStream::open(static fn (string $bytes): bool => $session->accept($sink, $bytes));

        try {
            $this->encryptor->unseal(
                EncryptionScopeId::forDocument($scopeId),
                $sealed,
                $plaintext,
                UploadEvidence::ORIGINAL_ASSOCIATED_DATA,
            );
        } finally {
            \fclose($plaintext);
        }
    }

    private function claim(string $documentId): ?ScanClaim
    {
        return $this->transactionManager->transactional(function () use ($documentId): ?ScanClaim {
            $claim = $this->leases->claim($documentId);

            if ($claim instanceof ScanClaim && !$claim->firstClaimWasRecorded) {
                $this->custody->append($documentId, CustodyEvent::ScanClaimed, null);
            }

            return $claim;
        });
    }

    private function afterVerdict(ScanVerdict $verdict, ScanClaim $claim): ScanResult
    {
        $result = match ($verdict) {
            ScanVerdict::Clean => [DocumentState::Ready, CustodyEvent::ScanReady, ScanResult::Ready],
            ScanVerdict::Infected => [
                DocumentState::Quarantined,
                CustodyEvent::ScanQuarantined,
                ScanResult::Quarantined,
            ],
            ScanVerdict::LimitExceeded => [DocumentState::ScanFailed, CustodyEvent::ScanFailed, ScanResult::Failed],
        };

        if (!$this->post($claim, $result[0], $result[1])) {
            return ScanResult::Aborted;
        }

        if (ScanVerdict::LimitExceeded === $verdict) {
            $this->alarm($claim, 'scanner-limit');
        }

        return $result[2];
    }

    private function afterFailure(
        Throwable $throwable,
        ScanClaim $claim,
        ScanSession $session,
        ScannerHealth $health,
    ): ScanResult {
        // A database fault met while heartbeating is neither a lost claim nor a scanner refusal.
        $failure = $session->failure();

        if ($failure instanceof Throwable) {
            throw $failure;
        }

        if ($session->lost() || $throwable instanceof DekDestroyed) {
            return ScanResult::Aborted;
        }

        if ($throwable instanceof DecryptionFailed || $throwable instanceof ObjectNotFound) {
            if (!$this->post($claim, DocumentState::ScanFailed, CustodyEvent::ScanFailed)) {
                return ScanResult::Aborted;
            }

            $this->alarm($claim, $throwable instanceof ObjectNotFound ? 'object-missing' : 'authentication-failed');

            return ScanResult::Failed;
        }

        $this->stopTheRunWhenTheScannerCannotBeReached($throwable, $health);

        if ($this->isNoVerdict($throwable, $session)) {
            return $this->returnToReceived($claim, $session, $health);
        }

        throw $throwable;
    }

    /**
     * The probe passed and the connect did not: the run must not claim another document on that scanner.
     */
    private function stopTheRunWhenTheScannerCannotBeReached(Throwable $throwable, ScannerHealth $health): void
    {
        if ($throwable instanceof ScannerUnavailable) {
            $health->markDown();
        }
    }

    /**
     * What ends a scan with no verdict: the scanner unreachable or reset, a transient local read or storage
     * error. Anything else is a defect of ours and propagates.
     */
    private function isNoVerdict(Throwable $throwable, ScanSession $session): bool
    {
        return $throwable instanceof ScannerUnavailable
            || $throwable instanceof ScanInterrupted
            || $throwable instanceof DocumentStorageException
            || $throwable instanceof StreamTransferFailed
            || $session->scannerRefused();
    }

    private function returnToReceived(ScanClaim $claim, ScanSession $session, ScannerHealth $health): ScanResult
    {
        // Only a claim that began streaming can be counted, so the probe is asked for only then.
        $down = $session->streamingStarted() && $health->isDown();

        $outcome = $this->transactionManager->transactional(function () use ($claim, $down): ReturnOutcome {
            $outcome = $this->leases->returnToReceived($claim->documentId, $claim->epoch, $down, false);

            if (ReturnOutcome::Failed === $outcome) {
                $this->custody->append($claim->documentId, CustodyEvent::ScanFailed, null);
            }

            return $outcome;
        });

        if (ReturnOutcome::Failed === $outcome) {
            $this->alarm($claim, 'return-cap-reached');
        }

        return ReturnOutcome::Lost === $outcome ? ScanResult::Aborted : ScanResult::Returned;
    }

    private function post(ScanClaim $claim, DocumentState $state, CustodyEvent $event): bool
    {
        return $this->transactionManager->transactional(function () use ($claim, $state, $event): bool {
            if (!$this->leases->settle($claim->documentId, $claim->epoch, $state)) {
                return false;
            }

            $this->custody->append($claim->documentId, $event, null);

            return true;
        });
    }

    private function alarm(ScanClaim $claim, string $reason): void
    {
        $this->logger->error('A document could not be scanned and will not be retried until an operator acts.', [
            'documentId' => $claim->documentId,
            'reason' => $reason,
        ]);
    }
}
