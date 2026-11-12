<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Scanning;

use Erpify\Documents\Document\Domain\Scanning\MalwareScanner;

/**
 * The scanner's health as ONE sweep run sees it: the return path probes once and applies the answer to every row of
 * the run, so a scanner that is down costs one probe, not one per document. Built per run and never shared, since the
 * answer is a fact about the moment it was asked.
 */
final class ScannerHealth
{
    private ?bool $down = null;

    public function __construct(
        private readonly MalwareScanner $scanner,
    ) {
    }

    /** Probes on the first call only. */
    public function isDown(): bool
    {
        return $this->down ??= !$this->scanner->probe();
    }

    /**
     * Records that the scanner turned out to be down after a probe passed — a connect that failed on a claimed
     * document — so the run claims nothing more.
     */
    public function markDown(): void
    {
        $this->down = true;
    }

    /** Whether the scanner is known down, by a probe or by {@see markDown()}; never probes. */
    public function knownDown(): bool
    {
        return true === $this->down;
    }
}
