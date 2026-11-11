<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain;

use Erpify\Documents\Document\Domain\Exception\InvalidOriginalFilename;

/**
 * What the PUT's query said about the original filename, already judged: either one valid
 * {@see OriginalFilename} or a refusal. The HTTP edge builds it from the raw `filename` pairs, so no
 * caller-supplied string ever reaches the use case; the use case decides only WHEN the refusal matters — on a
 * `Declared` upload it is raised, on a committed one the name is ignored.
 */
final readonly class FilenameSubmission
{
    private function __construct(private ?OriginalFilename $filename)
    {
    }

    /**
     * @param list<string> $values every value the raw query carried under the `filename` name, in order
     */
    public static function fromQueryValues(array $values): self
    {
        if (1 !== \count($values)) {
            return new self(null);
        }

        try {
            return new self(OriginalFilename::fromString($values[0]));
        } catch (InvalidOriginalFilename) {
            return new self(null);
        }
    }

    public static function of(OriginalFilename $filename): self
    {
        return new self($filename);
    }

    /**
     * @throws InvalidOriginalFilename when the query carried no valid single filename
     */
    public function filenameOrRefuse(): OriginalFilename
    {
        return $this->filename ?? throw InvalidOriginalFilename::create();
    }
}
