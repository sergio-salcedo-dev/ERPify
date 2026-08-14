<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance;

use Erpify\Iam\Session\Application\PruneRetiredSessions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The unattended arm of the session retention sweep, and the arm that carries the guarantee: the command
 * beside it has to be typed, so a retention floor that existed only there would hold exactly as often as
 * somebody remembered it.
 *
 * **Silent on purpose, unlike the two maintenance handlers that log.** Those two report a finding — a
 * divergence somebody must repair — whereas a prune reports work it already did, and a daily line saying it
 * deleted nothing is retention spent on repetition. A failure is a different matter and leaves by the other
 * door: nothing here catches, so a store outage propagates, Messenger logs it at `critical` and Sentry
 * captures it. Scheduler transports are not failure transports, so the throw neither retries nor lands in
 * `failed`.
 *
 * **It lives in `Iam/Identity` because the schedule does**, and it reaches `Iam/Session` through that
 * context's published use case — the same shape as the three seams already crossing this way
 * (`SessionMintingSuccessListener` → `StartSession`, `RevokeSessionsBestEffort` → `RevokeAllSessions`,
 * `FulfilIdentityErasure` → `PurgeUserSessions`), and registered as one in `api/.bounded-context-allowlist`.
 */
#[AsMessageHandler]
final readonly class PruneRetiredSessionsHandler
{
    public function __construct(
        private PruneRetiredSessions $pruneRetiredSessions,
    ) {
    }

    public function __invoke(PruneRetiredSessionsMessage $message): void
    {
        unset($message);

        $this->pruneRetiredSessions->prune();
    }
}
