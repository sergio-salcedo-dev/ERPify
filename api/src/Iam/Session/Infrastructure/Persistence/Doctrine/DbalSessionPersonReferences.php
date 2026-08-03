<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Override;

/**
 * {@link PersonReferenceSource} over `iam_session.user_id` via plain DBAL — a `DISTINCT` read, never a
 * mutation and never a hydration.
 *
 * Deliberately WITHOUT the temporal-validity predicate every other read of this table carries. Expiry is a
 * read-side predicate rather than a persisted transition and nothing reaps an expired row, so
 * `SessionRepository::findByUserId()` — the only existing listing read — cannot see precisely the rows that
 * keep a person's `user_id`, `ip` and `device` for ever. Filtering by `status = ACTIVE AND expires_at > now`
 * here would make this control blind to the majority of what it exists to find.
 *
 * Ids only, and that is a confidentiality constraint rather than a convenience: a session row carries `ip`
 * and `device`, short-lived operational PII the aggregate deliberately keeps out of the audit trail, and a
 * source that returned rows would put it in an operator's console instead.
 *
 * `DISTINCT` is load-bearing — one person owns many sessions over time, so without it a subject is reported
 * once per session ever opened. `ORDER BY` keeps the set stable across runs so a diffing alert cannot fire
 * on Postgres's choice of plan.
 */
final readonly class DbalSessionPersonReferences implements PersonReferenceSource
{
    public function __construct(private Connection $connection)
    {
    }

    #[Override]
    public function axis(): PersonReferenceAxis
    {
        return PersonReferenceAxis::of(Session::class . '::$userId');
    }

    #[Override]
    public function retainedPersonIds(): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT user_id FROM iam_session ORDER BY user_id',
        );

        return \array_values(\array_filter($ids, \is_string(...)));
    }
}
