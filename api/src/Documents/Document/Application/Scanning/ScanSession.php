<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Scanning;

use Erpify\Documents\Document\Domain\Scanning\DocumentScanLeases;
use Erpify\Documents\Document\Domain\ScanPolicy;
use Throwable;

/**
 * What one claim does while its plaintext is on the wire: marks that streaming began immediately before the first
 * byte goes to the scanner, renews the lease whenever the heartbeat interval has passed, and remembers why a write
 * was refused. The decryptor sees only a sink that stopped accepting bytes, so the reason lives here.
 *
 * A compare-and-set that loses (the epoch moved, the row left `Scanning`, the deadline passed) makes the session
 * {@see lost()}: the worker stops and posts no verdict. A database fault is kept as {@see failure()} for the caller
 * to rethrow — it is neither a lost claim nor a scanner refusal.
 */
final class ScanSession
{
    private bool $streaming = false;

    private bool $lost = false;

    private bool $scannerRefused = false;

    private ?Throwable $failure = null;

    private float $lastBeat;

    public function __construct(
        private readonly DocumentScanLeases $leases,
        private readonly string $documentId,
        private readonly int $epoch,
        private readonly int $heartbeatIntervalSeconds = ScanPolicy::HEARTBEAT_SECONDS,
    ) {
        $this->lastBeat = $this->now();
    }

    /**
     * Passes `$bytes` to the scanner's `$sink`, after the streaming mark on the first write and a heartbeat when one
     * is due.
     *
     * @param resource $sink
     */
    public function accept($sink, string $bytes): bool
    {
        try {
            $ready = $this->beforeWrite();
        } catch (Throwable $throwable) {
            $this->failure = $throwable;

            return false;
        }

        return $ready && $this->forward($sink, $bytes);
    }

    /**
     * @param resource $sink
     */
    private function forward($sink, string $bytes): bool
    {
        $written = 0;
        $length = \strlen($bytes);

        while ($written < $length) {
            $count = \fwrite($sink, \substr($bytes, $written));

            if (false === $count || 0 === $count) {
                $this->scannerRefused = true;

                return false;
            }

            $written += $count;
        }

        return true;
    }

    /**
     * The poll between waits for the verdict: renews the lease when due, and answers whether the claim is still ours.
     */
    public function keepAlive(): bool
    {
        try {
            return !$this->lost && $this->renewIfDue();
        } catch (Throwable $throwable) {
            $this->failure = $throwable;

            return false;
        }
    }

    public function lost(): bool
    {
        return $this->lost;
    }

    /** Whether the streaming mark was set, which decides how a return without a verdict is counted. */
    public function streamingStarted(): bool
    {
        return $this->streaming;
    }

    public function scannerRefused(): bool
    {
        return $this->scannerRefused;
    }

    public function failure(): ?Throwable
    {
        return $this->failure;
    }

    private function beforeWrite(): bool
    {
        if ($this->lost) {
            return false;
        }

        return $this->streaming ? $this->renewIfDue() : $this->startStreaming();
    }

    /** The mark that streaming began, set immediately before the first byte goes to the scanner. */
    private function startStreaming(): bool
    {
        if (!$this->leases->markStreaming($this->documentId, $this->epoch)) {
            $this->lost = true;

            return false;
        }

        $this->streaming = true;
        $this->lastBeat = $this->now();

        return true;
    }

    private function renewIfDue(): bool
    {
        if ($this->now() - $this->lastBeat < $this->heartbeatIntervalSeconds) {
            return true;
        }

        if (!$this->leases->heartbeat($this->documentId, $this->epoch)) {
            $this->lost = true;

            return false;
        }

        $this->lastBeat = $this->now();

        return true;
    }

    /** Seconds on a monotonic clock; only differences mean anything. */
    private function now(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }
}
