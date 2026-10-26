<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Security;

use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Shared\ErrorContract\Infrastructure\Http\RateLimitSnapshot;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The budgets on the console's invitation resend, consumed at the controller edge before the use case runs. Every
 * resend mails the invitee and kills the link they already hold, so two buckets bound it:
 *  - per invitee, shared by every administrator — how often one person's working link can be destroyed and their
 *    mailbox written to, whoever asks;
 *  - per caller and invitee — the share of that bucket one identity may spend, so a single session, stolen or not,
 *    cannot drain the shared bucket and hold every other administrator's resend of that person shut.
 * The caller's share is spent first: a caller over its share is refused without touching the shared bucket.
 *
 * Both keys are HMACs under `kernel.secret` of case-folded ids, for two reasons. The column the invitee's id
 * selects is a Postgres `uuid`, which compares case-insensitively, so keying on the spelling would give one person
 * a bucket per spelling and the limit would stop being one — the defect
 * {@see \Erpify\Shared\Token\Domain\SelectorBudgetKey} records for the selector axis. And the limiter serialises
 * its key into the bucket it stores in the cache pool, where it lives for up to two windows and no erasure path
 * reaches it, so a raw id would put a person's identifier there; the HMAC is stable per person and names nobody.
 * The caller hands it a well-formed UUID: a malformed one is answered 400 before any budget is touched.
 *
 * It refuses out loud with the shared {@see RateLimitExceeded} (429 `rate-limited`) and stamps the refusing
 * bucket's {@see RateLimitSnapshot} on the request, so the response carries the `Retry-After` of the budget that
 * said no. The route sits behind an administrator session that can already read the register, so a per-target
 * 429 discloses nothing that session could not learn.
 */
final readonly class InvitationResendThrottle
{
    public function __construct(
        #[Autowire(service: 'limiter.invitation_resend_per_invitee')]
        private RateLimiterFactoryInterface $perInviteeLimiter,
        #[Autowire(service: 'limiter.invitation_resend_per_actor_invitee')]
        private RateLimiterFactoryInterface $perActorInviteeLimiter,
        private RequestStack $requestStack,
        private ActorContextFactory $actorContext,
        #[Autowire(param: 'kernel.secret')]
        private string $secret,
    ) {
    }

    /**
     * @throws RateLimitExceeded when the caller's share, or the invitee's shared budget, is spent for the window
     */
    public function ensureWithinBudget(string $invitedUserId): void
    {
        $this->spend(
            $this->perActorInviteeLimiter,
            $this->actorKeyOf($this->actorContext->current(), $invitedUserId),
        );
        $this->spend($this->perInviteeLimiter, $this->keyOf($invitedUserId));
    }

    public function keyOf(string $invitedUserId): string
    {
        return \hash_hmac('sha256', \mb_strtolower($invitedUserId), $this->secret);
    }

    public function actorKeyOf(ActorContext $actor, string $invitedUserId): string
    {
        return \hash_hmac(
            'sha256',
            $actor->type->value . ':' . \mb_strtolower($actor->actorId ?? '') . '|' . \mb_strtolower($invitedUserId),
            $this->secret,
        );
    }

    /**
     * @throws RateLimitExceeded
     */
    private function spend(RateLimiterFactoryInterface $limiter, string $key): void
    {
        $snapshot = RateLimitSnapshot::of($limiter->create($key)->consume(), $key);

        if ($snapshot->accepted) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        if ($request instanceof Request) {
            $snapshot->stampOn($request);
        }

        throw $snapshot->refusal();
    }
}
