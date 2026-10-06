<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance;

use Erpify\Iam\Identity\Application\ResweepErasedSubjects;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The only arm of the erasure re-sweep: it has no command, because a re-sweep somebody had to remember to type
 * would bound nothing.
 *
 * Silent on purpose, like the session prune beside it: a tick that rewrote something already leaves its own
 * `GDPR_ERASURE_EXECUTED` entry, and one that rewrote nothing has nothing to say. A failure leaves by the other
 * door — nothing here catches, so Messenger logs it at `critical` and the next tick repeats the idempotent
 * passes.
 */
#[AsMessageHandler]
final readonly class ResweepErasedSubjectsHandler
{
    public function __construct(
        private ResweepErasedSubjects $resweepErasedSubjects,
    ) {
    }

    public function __invoke(ResweepErasedSubjectsMessage $message): void
    {
        unset($message);

        $this->resweepErasedSubjects->resweep();
    }
}
