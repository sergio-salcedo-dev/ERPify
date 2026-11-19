<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Derivative;

use Erpify\Shared\Images\Domain\DerivativeOrigin;

/**
 * The origin every derivative of a document carries: this context's tag and the document id. Spelled once, so the
 * materialising use case, the guard and the release sweep cannot disagree on it — a derivative stored under one
 * spelling and released under another would never be found.
 */
final class DocumentDerivativeOrigin
{
    public const string TYPE = 'Documents.Document';

    private function __construct()
    {
    }

    public static function of(string $documentId): DerivativeOrigin
    {
        return new DerivativeOrigin(self::TYPE, $documentId);
    }
}
