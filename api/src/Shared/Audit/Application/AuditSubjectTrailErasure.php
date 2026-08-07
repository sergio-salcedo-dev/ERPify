<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

use Erpify\Shared\Audit\Domain\AuditResource;

/**
 * The audit module's whole part in erasing a person: both axes of `audit_log`, taken in the one order that
 * is safe, behind a single collaborator.
 *
 * It exists because the sequence is not the caller's business and the caller cannot be trusted with it. Three
 * separate ports — {@see AuditSubjectRowLock}, {@see AuditActorAnonymiser}, {@see AuditResourceAnonymiser} —
 * have to be invoked in a fixed order, and two of the ways to get that order wrong are silent:
 *
 *  - Taking the row lock after the first `UPDATE` orders nothing that has not already run, so the ABBA
 *    deadlock between the two axes stays reachable while every gate is green.
 *  - Minting a second pseudonym for the resource pass splits one person into two anonymous identities.
 *
 * Both were knowledge the orchestrating use case held by hand, and both are now unexpressible: the lock is
 * inside {@see beginForSubject()}, and {@see completeForSubject()} takes that method's own return value rather
 * than a bare string, so there is no signature a freshly minted pseudonym fits.
 *
 * **Why two methods and not one.** A `security` audit entry naming the subject as its resource is written
 * BETWEEN the passes: the resource anonymiser's `UPDATE` matches rows that already exist, so the entry has to
 * be inserted before it and after the actor pass has minted the pseudonym. That INSERT is the caller's — it
 * is compliance evidence about the caller's operation, not an audit-module concern — so the seam has to open
 * there. The temporal coupling is real rather than incidental, and the names say so instead of hiding it.
 *
 * The three ports stay: {@see \Erpify\Shared\Audit\Infrastructure\Cli\EraseActorAuditTrailCommand} erases an
 * actor trail alone and has no resource axis to order against, so folding them away would cost it a seam it
 * legitimately uses.
 */
interface AuditSubjectTrailErasure
{
    /**
     * Locks every `audit_log` row naming `$subject` on either axis, then anonymises the rows they authored.
     *
     * **Must run inside the caller's transaction** — a lock taken outside one is released at the end of the
     * statement and protects nothing.
     *
     * @return ActorAnonymisationResult the pseudonym {@see completeForSubject()} must be given, and the
     *                                  number of rows the actor pass rewrote
     */
    public function beginForSubject(AuditResource $subject): ActorAnonymisationResult;

    /**
     * Anonymises the rows naming `$subject` as a resource, under the pseudonym `beginForSubject()` minted.
     *
     * It takes the whole {@see ActorAnonymisationResult} rather than the pseudonym string it carries, so that
     * the value can only have come from the actor pass. Both axes of one person must collapse onto one
     * anonymous identity — a second pseudonym splits them, gains nothing, and is the failure a `string`
     * parameter would accept without complaint.
     *
     * @return int rows whose `resource_id` was rewritten
     */
    public function completeForSubject(AuditResource $subject, ActorAnonymisationResult $anonymisation): int;
}
