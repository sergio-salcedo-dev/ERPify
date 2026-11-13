<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Scanning;

use RuntimeException;

/**
 * The scanner could not be reached before the scan began: nothing was sent, so the return is an outage and not an
 * attempt. Carries no address.
 */
final class ScannerUnavailable extends RuntimeException
{
    public static function create(): self
    {
        return new self('The malware scanner is unavailable.');
    }
}
