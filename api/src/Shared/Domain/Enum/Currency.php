<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Enum;

/**
 * ISO 4217 alphabetic currency codes. String-backed so the case value is the
 * canonical code persisted in money/currency columns. Extend with additional
 * cases as new currencies are supported.
 */
enum Currency: string
{
    case EUR = 'EUR';
    case USD = 'USD';
    case GBP = 'GBP';
    case CHF = 'CHF';
    case JPY = 'JPY';
    case CAD = 'CAD';
    case AUD = 'AUD';
}
