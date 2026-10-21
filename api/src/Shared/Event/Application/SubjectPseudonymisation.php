<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;

/**
 * The (subject, pseudonym) pair {@see EventStoreSubjectAnonymiser} rewrites, bound into one value so it never
 * travels as two adjacent strings. A swapped pair is not an error the statement can report: it searches for
 * the pseudonym, matches nothing, answers 0, and the compliance entry reads exactly like "this subject had no
 * events" while the real identifier stays in the log. The pseudonym is therefore a {@see SubjectPseudonym},
 * not a `string`, so the swap fails to type-check rather than running.
 *
 * Both members are validated UUIDs — the adapter interpolates each into a regular expression — and the pair
 * may not be the same identifier in any case: that rewrite would report every matched row as anonymised while
 * the id it claims to have erased is still there. The pseudonym is re-checked here even though
 * {@see SubjectPseudonym::fromString()} already validates it, because a `SubjectPseudonym` built without its
 * factory — through reflection or `unserialize()` — carries no such guarantee; the adapter re-checks for the
 * same reason.
 */
final readonly class SubjectPseudonymisation
{
    private function __construct(
        public string $subjectId,
        public string $pseudonym,
    ) {
    }

    /**
     * @throws InvalidUuidException     when either member is not a well-formed UUID (see the class docblock)
     * @throws InvalidArgumentException when the pseudonym is the subject's own identifier
     */
    public static function of(string $subjectId, SubjectPseudonym $pseudonym): self
    {
        Uuid::ensure($subjectId);
        Uuid::ensure($pseudonym->value);

        if (0 === \strcasecmp($subjectId, $pseudonym->value)) {
            throw new InvalidArgumentException('A subject cannot be pseudonymised under its own identifier.');
        }

        return new self($subjectId, $pseudonym->value);
    }
}
