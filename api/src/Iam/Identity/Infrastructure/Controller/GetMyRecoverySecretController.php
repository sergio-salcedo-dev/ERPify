<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use DateTimeInterface;
use Erpify\Iam\Identity\Application\Resource\RecoverySecretResource;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Repository\RecoverySecretRepository;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * `GET /me/recovery-secret` — whether the caller holds a recovery secret, and the two dates that govern it.
 * No `#[IsGranted]`, like its siblings under `/me`: the subject is the caller's own identity.
 *
 * **This read is what makes the ten-year TTL governable rather than merely accepted.** The secret survives a
 * password change by design, is not rotated when spent, and stops being redeemable only by redemption,
 * revocation, expiry or subject erasure — the first two and the last remove the row, while expiry merely
 * makes it unusable and nothing sweeps it. So holding it equals holding a recovery credential until one of
 * those four. An owner who
 * cannot see that it exists, when it was issued and when it lapses has no way to decide about any of that;
 * this endpoint and the revoke beside it are the whole of the governance.
 *
 * It reads through the repository directly rather than a use case, and that is the read/write asymmetry the
 * project already applies elsewhere: there is no decision, no transaction and no invariant here — one lookup
 * and a projection. Wrapping it would add a class whose body is the line below.
 *
 * The unlocked finder is deliberate: a locking read would take a row lock for a page render, and the
 * `ForUpdate` pair exists precisely so that the resolving lookups authorize nothing.
 */
#[Route('/me/recovery-secret', name: self::ROUTE_NAME, methods: ['GET'])]
final readonly class GetMyRecoverySecretController
{
    public const string ROUTE_NAME = 'iam_me_get_recovery_secret';

    public function __construct(
        private RecoverySecretRepository $secrets,
        private ResourceResponder $resourceResponder,
    ) {
    }

    public function __invoke(#[CurrentUser] SecurityUser $user): Response
    {
        $identityId = $user->id() ?? throw new LogicException('An authenticated identity must have an id.');

        $secret = $this->secrets->findByUserId($identityId);

        if (!$secret instanceof RecoverySecret) {
            return $this->resourceResponder->respond(new RecoverySecretResource(false, null, null));
        }

        return $this->resourceResponder->respond(new RecoverySecretResource(
            true,
            $secret->getCreatedAt()->format(DateTimeInterface::ATOM),
            $secret->expiresAt()->format(DateTimeInterface::ATOM),
        ));
    }
}
