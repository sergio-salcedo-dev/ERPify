<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Upload;

use Symfony\Component\Uid\Uuid;

/**
 * Mints the id of a document's encryption scope. Version 4, not the v7 every other id here uses: a v7 carries
 * its mint time, and the scope id is the one identifier that must say nothing about the document — not when it
 * was filed, not which document it is — because it names the key, the staging key and the final object.
 */
final class DocumentScopeId
{
    private function __construct()
    {
    }

    public static function mint(): string
    {
        return Uuid::v4()->toRfc4122();
    }
}
