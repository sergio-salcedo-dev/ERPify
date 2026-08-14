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
 * read-side predicate rather than a persisted transition, so `SessionRepository::findByUserId()` — the only
 * listing read — cannot see precisely the rows that keep a person's `user_id`, `ip` and `device` after the
 * session itself stopped being admissible. Filtering by `status = ACTIVE AND expires_at > now` here would
 * make this control blind to the majority of what it exists to find.
 *
 * The retention sweep (`iam:session:prune`) bounds how long such a row lives, and it does not weaken the
 * argument above — it sharpens it. A row inside its retention window is exactly the row this source must
 * still report: it holds a real person's id, it is invisible to every other read of the table, and whether an
 * erasure reached it is the question the reconciler is asking. Nor does the sweep make this control
 * redundant, because the two answer different questions — the sweep removes a row for being OLD, this reports
 * a row for naming a person who no longer exists, and a freshly erased subject is neither.
 *
 * **What the sweep does bound is the DETECTION window, and that is a real limit rather than a nuance.** A row
 * orphaned by a broken erasure path is reported on every tick until the sweep deletes it — at most ~97 days
 * after the session lapsed. After that the divergence disappears from the report, with no record that it ever
 * stood: the personal datum is gone (which is the outcome that matters) but the evidence that a write path
 * skipped its erasure goes with it. That is the opposite trade from `audit_log`, where the rows proving an
 * erasure ran are exempt from their prune for ever, and it is affordable here only because a session row is
 * not itself evidence of anything. An erasure defect that only ever produced sessions older than the window
 * would therefore be invisible to this control.
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
