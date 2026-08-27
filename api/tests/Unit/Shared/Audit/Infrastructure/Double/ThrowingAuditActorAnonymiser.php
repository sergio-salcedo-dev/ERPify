<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double;

use Erpify\Shared\Audit\Application\ActorAnonymisationResult;
use Erpify\Shared\Audit\Application\AuditActorAnonymiser;
use Override;
use RuntimeException;

/**
 * {@see AuditActorAnonymiser} that fails on one chosen method, so a command test can drive the two database
 * failures separately. They are not the same failure and the command must not report them as one: a failed
 * count attempted nothing and is safe to repeat, while a failed `UPDATE` may have committed without
 * acknowledging.
 *
 * Per-method rather than failing on both, because a double that throws everywhere cannot tell a command that
 * guards one call from a command that guards the other — which is the distinction under test.
 *
 * @internal
 */
final readonly class ThrowingAuditActorAnonymiser implements AuditActorAnonymiser
{
    public const string MESSAGE = 'audit trail unavailable';

    private function __construct(
        private bool $failOnCount,
        private int $matchCount,
    ) {
    }

    public static function onCount(): self
    {
        return new self(true, 0);
    }

    public static function onAnonymise(int $matchCount = 3): self
    {
        return new self(false, $matchCount);
    }

    #[Override]
    public function countFor(string $actorId): int
    {
        if ($this->failOnCount) {
            throw new RuntimeException(self::MESSAGE);
        }

        return $this->matchCount;
    }

    #[Override]
    public function anonymise(string $actorId): ActorAnonymisationResult
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
