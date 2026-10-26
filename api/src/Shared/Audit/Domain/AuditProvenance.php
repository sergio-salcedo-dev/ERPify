<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

use DateTimeImmutable;

/**
 * Who acted, when, under which correlation id and from which client: the trusted context an audit entry is
 * sealed with at the moment of the act, as opposed to what the act was. The five travel together because
 * one collaborator captures them together — in production the request cycle, before the entry leaves it for
 * an off-request writer — so a caller supplies them as one fact rather than as five positional arguments.
 *
 * `ip` and `userAgent` are attacker-controlled and stay tainted downstream. A captured value equal to
 * {@see AuditRedaction::SENTINEL} is rewritten here, on the way in, so the literal is written by an erasure
 * alone (see {@see AuditRedaction}).
 */
final readonly class AuditProvenance
{
    public ?string $ip;

    public ?string $userAgent;

    public function __construct(
        public ActorContext $actor,
        public string $correlationId,
        public DateTimeImmutable $occurredOn,
        ?string $ip = null,
        ?string $userAgent = null,
    ) {
        $this->ip = AuditRedaction::neutraliseCaptured($ip);
        $this->userAgent = AuditRedaction::neutraliseCaptured($userAgent);
    }
}
