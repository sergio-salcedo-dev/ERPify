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
 * The key is the id case-folded and nothing more. The column it selects is a Postgres `uuid`, which compares
 * case-insensitively, so keying on the spelling would give one person a bucket per spelling and the limit would
 * stop being one — the defect {@see \Erpify\Shared\Token\Domain\SelectorBudgetKey} records for the selector
 * axis. It is not validated here: a malformed id spends a bucket nobody else can reach, and the use case answers
 * it 400 before any lookup.
 *
 * It refuses out loud with the shared {@see RateLimitExceeded} (429 `rate-limited`) and stamps its
 * {@see RateLimitSnapshot} on the request, so the response carries the `Retry-After` of the budget that said no.
 * The route sits behind an administrator session that can already read the register, so a per-target 429
 * discloses nothing that session could not learn. The person's id travels only as the exception's
 * `limiterKey`, which neither the response body nor the per-error log line serialises.
 */
final readonly class InvitationResendThrottle
{
    public function __construct(
        #[Autowire(service: 'limiter.invitation_resend_per_invitee')]
        private RateLimiterFactoryInterface $perInviteeLimiter,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @throws RateLimitExceeded when the invitee's resend budget is spent for the current window
     */
    public function ensureWithinBudget(string $invitedUserId): void
    {
        $key = self::keyOf($invitedUserId);
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

    public static function keyOf(string $invitedUserId): string
    {
        return \mb_strtolower(\trim($invitedUserId));
    }
}
