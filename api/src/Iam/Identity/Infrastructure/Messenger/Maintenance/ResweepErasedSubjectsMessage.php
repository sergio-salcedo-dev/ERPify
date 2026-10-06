<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance;

/**
 * Scheduler tick that re-runs the erasure's anonymising passes over the subjects erased within the last
 * window ({@see \Erpify\Iam\Identity\Application\ResweepErasedSubjects}).
 *
 * It carries no payload, and must not: the subjects come from `identity_erasure_resweep`, read when the tick is
 * handled. A message carrying a subject id would be a person's id on a scheduler transport, which is what
 * `api/.persistent-transport-policy` exists to keep off every queue.
 */
final readonly class ResweepErasedSubjectsMessage
{
}
