<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Doctrine\DBAL\Exception as DbalException;
use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Session\Application\StartSession;
use Erpify\Iam\Session\Domain\Exception\SessionAdmissionUnresolvable;
use Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable;
use Erpify\Iam\Session\Domain\Exception\SessionSubjectNotAdmissible;
use Erpify\Iam\Session\Domain\SubjectAdmission;
use Erpify\Organization\Membership\Application\FindUserOrganizationId;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Throwable;

/**
 * Mints the server-side session once an identity is admitted. It listens on {@see LoginSuccessEvent} — which
 * the firewall dispatches only after the user checker's post-auth admission — so the session is minted
 * strictly post-admission, never for a rejected identity. This is the cross-sub-module orchestrator: it reads
 * the admission-time `organizationId` from the user's membership (the authoritative user↔org link) and hands
 * primitives to
 * {@see StartSession}, so the session subsystem stays unaware of `SecurityUser` and of `Organization`.
 *
 * Priority is below every built-in `LoginSuccessEvent` subscriber (the session-id `migrate` runs at 0), so the
 * `iamSessionId` written into the bag is set after the anti-fixation regeneration and survives it.
 * {@see LoginController} still answers 204 — minting neither couples to nor rewrites the login response.
 *
 * Fail-closed: if minting or the membership lookup throws, there is no live session, so the native cookie
 * is dropped explicitly (Symfony does not roll it back on its own) whatever the failure — "no `Session` row ⇒
 * no session". What the login then ANSWERS depends on what failed, because each answer tells the client and
 * the operator something different:
 *
 * - EVERY `Doctrine\DBAL\Exception` is the gate's own 503 `service-unavailable` — a lost connection and a
 *   malformed statement alike, by the convention `DoctrineSessionRepository::convertingStoreFailure()` applies
 *   to its own statements. The membership adapter raises DBAL untranslated, and so does the subject lock the
 *   minting takes outside the session repository, so it is translated here, where the session's vocabulary is;
 * - an identity with no membership is {@see SessionAdmissionUnresolvable} (500), a data gap no retry fixes;
 * - an identity suspended or deactivated while the login was in flight is refused by the mint itself, under the
 *   identity's row lock, and answered with the wall the firewall would have shown had the write landed first —
 *   403 `account-suspended` / `account-deactivated`, the same exceptions the authentication failure handler
 *   raises; one erased in that window keeps the mint's own uniform 401. The reason comes from the locked read,
 *   so nothing is re-read here. The re-authentication flows (accept, reset, redeem, change password) reach this
 *   listener too, so they answer the same way;
 * - anything else — the session store's own 503, a broken membership invariant, a programming error —
 *   propagates as it was raised: relabelling the last two as an outage would invite a retry that cannot
 *   succeed.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") — it is the seam between the firewall, the identity's walls,
 * the membership read and the session context, and translates each of their failures into one answer; every
 * type it names is one of those participants rather than a dependency it could shed.
 */
#[AsEventListener(event: LoginSuccessEvent::class, priority: self::PRIORITY)]
final readonly class SessionMintingSuccessListener
{
    public const int PRIORITY = -128;

    public function __construct(
        private StartSession $startSession,
        private FindUserOrganizationId $findUserOrganizationId,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof SecurityUser) {
            return;
        }

        $userId = $user->id();

        if (null === $userId) {
            return;
        }

        $request = $event->getRequest();

        try {
            $organizationId = $this->findUserOrganizationId->of($userId)
                ?? throw SessionAdmissionUnresolvable::noOrganization();
            $device = UserAgentDeviceLabel::fromUserAgent($request->headers->get('User-Agent'));

            $this->startSession->start($userId, $organizationId, $device, $request->getClientIp());
        } catch (Throwable $throwable) {
            $request->getSession()->invalidate();

            throw $this->answerFor($throwable);
        }
    }

    private function answerFor(Throwable $throwable): Throwable
    {
        if ($throwable instanceof DbalException) {
            return SessionStoreUnavailable::storeUnreachable($throwable);
        }

        if (!$throwable instanceof SessionSubjectNotAdmissible) {
            return $throwable;
        }

        return match ($throwable->reason()) {
            SubjectAdmission::Suspended => new AccountSuspended(),
            SubjectAdmission::Deactivated => new AccountDeactivated(),
            SubjectAdmission::Gone, SubjectAdmission::Admissible => $throwable,
        };
    }
}
