<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Event\Application\EventStoreSubjectAnonymiser;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The single parameterised `UPDATE` that D12 sanctions, on the Connection the EntityManager wraps so it
 * commits or rolls back with the erasure that called it.
 *
 * One statement covers every axis, and it has to: an implementation that rewrote only `aggregate_id` would
 * leave `SELECT aggregate_id FROM event_store` green while the identifier stayed alive in the `payload` of
 * the row beside it — a claim whose only evidence is the half it chose to look at.
 *
 * **Why the JSON columns are matched as text.** The identifier appears under different key names — `userId` in
 * two session events today, `invitedUserId` in every invitation row written before that envelope moved the id
 * to its aggregate column, which are not migrated and are still here. Matching by key name would be a
 * declaration that checks only itself: it would go green on exactly the events nobody remembered to list, and
 * on none of the historical shapes. Matching the value over the serialised column reaches any key, including
 * one nobody has written yet and one nobody remembers having written.
 *
 * **Case-insensitively, and that is not defensive programming.** `aggregate_id` is a `uuid` column, so
 * Postgres normalises its case on both sides of a comparison; `payload` and `metadata` are jsonb TEXT and it
 * does not, while the HTTP layer hands the identifier down in whatever case the caller spelled it
 * (`Uuid::ensure()` validates without normalising). The sibling axis was measured to leak exactly this way:
 * the same identifier in upper case passed a `LIKE` and failed an `ILIKE`.
 *
 * **Both arguments are validated UUIDs, and both have to be.** The pattern needs it because a regular
 * expression would otherwise read metacharacters out of it; the replacement needs it because Postgres gives
 * that string its own syntax too, where `\1`…`\9` are back-references and `\&` is the whole match.
 * `Uuid::ensure()` on each is what makes the pair safe, in code rather than in this comment: the canonical
 * 36-character form it accepts contains no character either syntax reads.
 *
 * **`metadata` is covered even though it is empty today, and that goes one column beyond D12's letter.** The
 * reason is NOT that the ADR reserves it for an actor id: D9 reserves `correlation_id` and `causation_id`,
 * which identify an event and a request rather than a person. It is that this anonymiser's guarantee is
 * defined over the ROW, not over a remembered list of columns — covering the third one removes an exception
 * rather than adding a responsibility. The predicate is by value, so while the column holds no person's
 * identifier the statement rewrites nothing; the day it does, nobody has to remember. Named here rather than
 * smuggled, and amended into D12 rather than left as a docblock speaking for an ADR.
 */
#[AsAlias(EventStoreSubjectAnonymiser::class)]
final readonly class DbalEventStoreSubjectAnonymiser implements EventStoreSubjectAnonymiser
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function anonymise(string $subjectId, string $pseudonym): int
    {
        Uuid::ensure($subjectId);
        Uuid::ensure($pseudonym);

        return (int) $this->connection->executeStatement(
            'UPDATE event_store SET '
            . 'aggregate_id = CASE WHEN aggregate_id = CAST(:subject_id AS UUID) '
            . 'THEN CAST(:pseudonym AS UUID) ELSE aggregate_id END, '
            . "payload = CAST(regexp_replace(payload::text, :subject_id, :pseudonym, 'gi') AS JSONB), "
            . "metadata = CAST(regexp_replace(metadata::text, :subject_id, :pseudonym, 'gi') AS JSONB) "
            . 'WHERE aggregate_id = CAST(:subject_id AS UUID) '
            . 'OR payload::text ILIKE :subject_pattern '
            . 'OR metadata::text ILIKE :subject_pattern',
            [
                'subject_id' => $subjectId,
                'pseudonym' => $pseudonym,
                'subject_pattern' => '%' . $subjectId . '%',
            ],
        );
    }
}
