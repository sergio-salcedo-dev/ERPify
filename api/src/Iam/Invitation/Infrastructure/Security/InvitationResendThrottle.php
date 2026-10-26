<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Security;

use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Shared\ErrorContract\Infrastructure\Http\RateLimitSnapshot;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The per-invitee budget on the console's invitation resend, consumed at the controller edge before the use case
 * runs. Every resend mails the invitee and kills the link they already hold, so the budget bounds how often one
 * person's working link can be destroyed and their mailbox written to — whichever administrator asks, which is
 * why it is keyed by the TARGET rather than by the caller.
 *
 * The key is an HMAC of the case-folded id under `kernel.secret`, for two reasons. The column the id selects is a
 * Postgres `uuid`, which compares case-insensitively, so keying on the spelling would give one person a bucket
 * per spelling and the limit would stop being one — the defect
 * {@see \Erpify\Shared\Token\Domain\SelectorBudgetKey} records for the selector axis. And the limiter
 * serialises its key into the bucket it stores in the cache pool, where it lives for up to two windows and no
 * erasure path reaches it, so the raw id would put a person's identifier there; the HMAC is stable per person
 * and names nobody. The caller hands it a well-formed UUID: a malformed one is answered 400 before the budget is
 * touched, so no spelling the use case would refuse can spend a person's budget.
 *
 * It refuses out loud with the shared {@see RateLimitExceeded} (429 `rate-limited`) and stamps its
 * {@see RateLimitSnapshot} on the request, so the response carries the `Retry-After` of the budget that said no.
 * The route sits behind an administrator session that can already read the register, so a per-target 429
 * discloses nothing that session could not learn.
 */
final readonly class InvitationResendThrottle
{
    public function __construct(
        #[Autowire(service: 'limiter.invitation_resend_per_invitee')]
        private RateLimiterFactoryInterface $perInviteeLimiter,
        private RequestStack $requestStack,
        #[Autowire(param: 'kernel.secret')]
        private string $secret,
    ) {
    }

    /**
     * @throws RateLimitExceeded when the invitee's resend budget is spent for the current window
     */
    public function ensureWithinBudget(string $invitedUserId): void
    {
        $key = $this->keyOf($invitedUserId);
        $snapshot = RateLimitSnapshot::of($this->perInviteeLimiter->create($key)->consume(), $key);

        if ($snapshot->accepted) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        if ($request instanceof Request) {
            $snapshot->stampOn($request);
        }

        throw $snapshot->refusal();
    }

    public function keyOf(string $invitedUserId): string
    {
        return \hash_hmac('sha256', \mb_strtolower($invitedUserId), $this->secret);
    }
}
