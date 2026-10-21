<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

/**
 * The phase the id-keyed `*BestEffort` projections report when their locked read finds no identity. Not a
 * failure: no row was owed and none was written. It is still reported, at `info` on the channel the class already
 * reports to, because the operation it projects has already happened — a lockout set, a mail sent, a secret
 * minted — and the trail will hold nothing for it. The subject is not named: the line says a row was withheld for
 * an identity that is gone, and naming that identity would outlive its erasure.
 *
 * Apart from {@see ReportsAuditFailureSafely} because the address-keyed throttle projection shares that trait and
 * not this outcome: an address naming nobody is its ordinary case, answered with a resource-less row.
 */
trait ReportsAbsentSubject
{
    private const string PHASE_SUBJECT_ABSENT = 'subject_absent';
}
