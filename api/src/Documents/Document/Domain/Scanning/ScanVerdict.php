<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Scanning;

/**
 * What a scanner concluded about one stream. `LimitExceeded` is a verdict of its own and never a clean one: a
 * stream the scanner would not fully examine is unscannable, and the document ends in `ScanFailed`.
 */
enum ScanVerdict
{
    case Clean;
    case Infected;
    case LimitExceeded;
}
