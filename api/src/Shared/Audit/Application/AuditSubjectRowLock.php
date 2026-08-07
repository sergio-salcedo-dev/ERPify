<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

use Erpify\Shared\Audit\Domain\AuditResource;

/**
 * Takes, in one deterministic order, every `audit_log` row a subject's erasure is about to rewrite on
 * **both** axes — the rows they authored and the rows that name them.
 *
 * It exists because ordering each erasure statement is not enough. {@see AuditActorAnonymiser} and
 * {@see AuditResourceAnonymiser} run as two statements inside one transaction, and their row sets overlap
 * across two concurrent erasures in opposite directions. With `Row1 = (actor: A, resource: B)` and
 * `Row2 = (actor: B, resource: A)`, erasing A takes Row1 then Row2 while erasing B takes Row2 then Row1 —
 * a textbook ABBA deadlock. Each statement matches a single row there, so an `ORDER BY` *inside* either one
 * changes nothing: the cycle is between the two statements, not within one.
 *
 * Locking the **union** is what closes it. Both erasures select the same set — every row where their subject
 * appears on either axis — and both take it in `id` order, so the second blocks behind the first at the
 * lowest shared row and no cycle can form. This is the pattern
 * {@see \Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DoctrineActiveAdministratorDirectory} uses
 * over `identity_user`, applied to the axis pair instead of the admin set.
 *
 * The reciprocal rows became possible when a second file started naming a person as an audit resource: an
 * administrator acting on another administrator. While the erasure was the only writer of that axis the
 * cycle was unreachable — the erasure hard-deletes the identity, so a subject once erased never acts again —
 * and that argument is gone. It is not restored by refusing administrators either: the refusal is lifted by
 * demotion, and a demoted pair still carries the rows they wrote as administrators.
 */
interface AuditSubjectRowLock
{
    /**
     * Locks every `audit_log` row naming `$subject` on either axis until the caller's transaction ends.
     *
     * **Must run inside that transaction**, before either anonymiser: a lock taken outside one is released
     * at the end of the statement and protects nothing. Callers that hold no transaction get no error and no
     * protection, which is why the only caller wraps the whole erasure in one.
     *
     * `$subject` carries the type and id of the resource axis; the actor axis is matched on the same id,
     * because both axes name the same person — that identity is the reason the two statements can collide.
     */
    public function lock(AuditResource $subject): void;
}
