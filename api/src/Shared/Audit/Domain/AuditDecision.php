<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

/**
 * The outcome of {@see AuditPolicy::decide()}: either "do not audit this interaction" or "audit it at
 * this {@see AuditLevel} under this action". The two factories make the third "capture-all, decide-late"
 * state unrepresentable — there is no way to produce a decision that is auditable yet carries no level or
 * action — so a caller that finds a {@see $level} simply emits, and a null one stays silent.
 *
 * `level` and `action` are non-null together (auditable) or null together (not auditable); the static
 * factories are the only constructors, so that pairing is an invariant, not a convention.
 */
final readonly class AuditDecision
{
    private function __construct(
        public bool $auditable,
        public ?AuditLevel $level,
        public ?string $action,
    ) {
    }

    public static function notAuditable(): self
    {
        return new self(false, null, null);
    }

    public static function auditable(AuditLevel $level, string $action): self
    {
        return new self(true, $level, $action);
    }
}
