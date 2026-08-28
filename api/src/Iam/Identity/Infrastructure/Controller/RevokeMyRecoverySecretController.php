<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use Erpify\Iam\Identity\Application\RevokeRecoverySecret;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * `DELETE /me/recovery-secret` — the owner destroys their own recovery credential. No `#[IsGranted]`, like
 * its siblings under `/me`: the subject is the caller's own identity.
 *
 * **This is the eviction the design owes in exchange for never destroying a secret silently.** A password
 * change leaves a live secret standing, deliberately, because a routine rotation must not take out the
 * recovery channel of someone with no shell to notice; the consequence is that revocation is the only way a
 * holder stops being one short of redeeming it or waiting out the decade.
 *
 * No `currentPassword` and no throttle, and both are decisions rather than omissions. Destroying the secret
 * grants nothing — it is the safe direction of this endpoint's failure — so demanding a credential re-proof
 * would spend the shared per-identity budget on an act that cannot help an attacker, and would leave an owner
 * who has locked themselves out of that budget unable to revoke a secret they believe is compromised.
 *
 * Answers **204** whether or not a row was there: the caller is the owner and already knows from the profile
 * read, so an idempotent revoke discloses nothing and spares the client a 404 for a state it asked to reach.
 */
#[Route('/me/recovery-secret', name: self::ROUTE_NAME, methods: ['DELETE'])]
final readonly class RevokeMyRecoverySecretController
{
    public const string ROUTE_NAME = 'iam_me_revoke_recovery_secret';

    public function __construct(private RevokeRecoverySecret $revokeRecoverySecret)
    {
    }

    public function __invoke(#[CurrentUser] SecurityUser $user): Response
    {
        $identityId = $user->id() ?? throw new LogicException('An authenticated identity must have an id.');

        $this->revokeRecoverySecret->revoke($identityId);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
