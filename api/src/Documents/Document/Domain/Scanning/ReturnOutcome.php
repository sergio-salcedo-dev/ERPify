<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Scanning;

/**
 * What a return of a scan to `Received` did. `Lost` means the row had moved on (another epoch, another state,
 * a lease still live for an expiry), and nothing was written.
 */
enum ReturnOutcome
{
    case Lost;
    case BackedOff;
    case Failed;
}
