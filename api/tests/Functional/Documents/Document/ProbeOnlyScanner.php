<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Domain\Scanning\MalwareScanner;
use Erpify\Documents\Document\Domain\Scanning\ScanVerdict;
use LogicException;
use Override;

/**
 * A scanner that only answers its probe: the sweeps that account a return ask it one question and never scan.
 */
final readonly class ProbeOnlyScanner implements MalwareScanner
{
    public function __construct(private bool $up)
    {
    }

    #[Override]
    public function probe(): bool
    {
        return $this->up;
    }

    #[Override]
    public function scan(callable $producer, callable $keepAlive): ScanVerdict
    {
        throw new LogicException('A probe-only scanner is never asked to scan.');
    }
}
