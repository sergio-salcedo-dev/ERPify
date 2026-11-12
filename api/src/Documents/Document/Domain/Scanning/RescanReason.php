<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Scanning;

/**
 * Why an operator reopens a `ScanFailed` document: a closed vocabulary, never free text, so a report can count them.
 */
enum RescanReason: string
{
    case ScannerOutageResolved = 'scanner_outage_resolved';
    case ScannerLimitsChanged = 'scanner_limits_changed';
    case FailureInvestigated = 'failure_investigated';
}
