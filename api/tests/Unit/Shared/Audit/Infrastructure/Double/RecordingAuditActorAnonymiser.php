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

    public function __construct(
        private readonly int $matchCount,
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

        return new ActorAnonymisationResult($this->pseudonym, $this->matchCount);
    }
}
