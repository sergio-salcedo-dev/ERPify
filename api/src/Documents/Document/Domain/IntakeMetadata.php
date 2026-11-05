<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain;

use DateTimeImmutable;

/**
 * What the intake flow supplies about a document beyond its bytes: the sealed original filename and the
 * retention terms it is held under. They travel together because one flow produces them at one moment and one
 * consumer reads them back, and every one of them is absent until that flow exists.
 *
 * The filename is ciphertext sealed under the document's own scope — never the plaintext name. The retention
 * reason and the legal basis are closed vocabularies owned by the upload story, so they are plain strings here
 * and the schema holds no CHECK on them.
 */
final readonly class IntakeMetadata
{
    public function __construct(
        public ?string $filenameCiphertext = null,
        public ?string $retentionReason = null,
        public ?string $legalBasis = null,
        public ?DateTimeImmutable $reviewAt = null,
    ) {
    }
}
