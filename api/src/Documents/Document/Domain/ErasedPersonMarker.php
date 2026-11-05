<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain;

/**
 * The one value a person's id is rewritten to when that person is erased from `document.uploader_id` and
 * `document_custody.actor_id`: the nil UUID, a valid value of the column type that names nobody.
 *
 * A constant, never a per-subject value. A pseudonym minted per subject and kept in the row would be a stable
 * identifier of that person across every document they touched, which is the linkability the erasure exists
 * to end; sealing the actor under the subject's own scope is circular, since the scope id derives from the
 * person id. Declared once so the statements that write it, the detective sources that exclude it and the
 * tests that plant it cannot disagree about the spelling.
 */
final class ErasedPersonMarker
{
    public const string UUID = '00000000-0000-0000-0000-000000000000';

    private function __construct()
    {
    }
}
