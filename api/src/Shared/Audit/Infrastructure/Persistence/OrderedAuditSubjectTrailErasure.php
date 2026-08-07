<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Erpify\Shared\Audit\Application\ActorAnonymisationResult;
use Erpify\Shared\Audit\Application\AuditActorAnonymiser;
use Erpify\Shared\Audit\Application\AuditResourceAnonymiser;
use Erpify\Shared\Audit\Application\AuditSubjectRowLock;
use Erpify\Shared\Audit\Application\AuditSubjectTrailErasure;
use Erpify\Shared\Audit\Domain\AuditResource;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link AuditSubjectTrailErasure} as the composition of the three ports it orders. It holds no SQL of its
 * own — the ordering IS its whole contribution, which is why the class is named after it.
 *
 * `beginForSubject()` is where the invariant lives: the lock is the first statement of the pair and there is
 * no path through this class that reaches the actor `UPDATE` without it. That is the difference between a
 * rule a caller must remember and one it cannot break.
 */
#[AsAlias(AuditSubjectTrailErasure::class)]
final readonly class OrderedAuditSubjectTrailErasure implements AuditSubjectTrailErasure
{
    public function __construct(
        private AuditSubjectRowLock $rowLock,
        private AuditActorAnonymiser $actorAnonymiser,
        private AuditResourceAnonymiser $resourceAnonymiser,
    ) {
    }

    #[Override]
    public function beginForSubject(AuditResource $subject): ActorAnonymisationResult
    {
        $this->rowLock->lock($subject);

        return $this->actorAnonymiser->anonymise($subject->id);
    }

    #[Override]
    public function completeForSubject(AuditResource $subject, string $pseudonym): int
    {
        return $this->resourceAnonymiser->anonymise($subject, $pseudonym);
    }
}
