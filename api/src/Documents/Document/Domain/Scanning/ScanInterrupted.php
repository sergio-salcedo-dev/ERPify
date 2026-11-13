<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Scanning;

use RuntimeException;

/**
 * The scan began and ended with no verdict: a reset, a timeout, a scanner error. Counts an attempt unless the
 * scanner is found down when the run re-probes.
 */
final class ScanInterrupted extends RuntimeException
{
    public static function create(): self
    {
        return new self('The malware scan ended with no verdict.');
    }
}
