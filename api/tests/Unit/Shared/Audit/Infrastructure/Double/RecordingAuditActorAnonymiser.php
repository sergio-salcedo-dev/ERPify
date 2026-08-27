<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double;

use Erpify\Shared\Audit\Application\ActorAnonymisationResult;
use Erpify\Shared\Audit\Application\AuditActorAnonymiser;
use Override;

/**
 * Spy {@see AuditActorAnonymiser}: reports a fixed match count and records each erasure, so a command test
 * can assert the dry-run / confirmation flow mutates (and self-audits) exactly when it should — and with
 * the resulting pseudonym, never the original id.
 *
 * @internal
 */
final class RecordingAuditActorAnonymiser implements AuditActorAnonymiser
{
    /** Named so a test with no handle on the instance can still assert the value the double will mint. */
    public const string PSEUDONYM = 'a1b2c3d4-0000-7000-8000-000000000000';

    public string $pseudonym = self::PSEUDONYM;

    public int $countForCalls = 0;

    /** @var list<string> */
    public array $anonymisedActorIds = [];

    /**
     * `$affectedRows` defaults to the match count because that is what the adapter answers when nothing
     * changes between the preview and the `UPDATE`. It is separable because the interesting case is the one
     * where they differ: `--force` takes no preview at all, and a row can vanish between the two, so a
     * double wiring them together makes "the UPDATE matched nothing" untestable — and that is the branch
     * standing between a re-run and an immortal evidence row claiming an erasure that did not happen.
     */
    public function __construct(
        private readonly int $matchCount,
        private readonly ?int $affectedRows = null,
    ) {
    }

    #[Override]
    public function countFor(string $actorId): int
    {
        ++$this->countForCalls;

        return $this->matchCount;
    }

    #[Override]
    public function anonymise(string $actorId): ActorAnonymisationResult
    {
        $this->anonymisedActorIds[] = $actorId;

        return new ActorAnonymisationResult($this->pseudonym, $this->affectedRows ?? $this->matchCount);
    }
}
