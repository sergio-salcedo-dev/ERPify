<?php

declare(strict_types=1);

namespace Erpify\Tests\DataFixtures;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Shared\Token\Domain\SingleUseToken;
use LogicException;

/**
 * Alice fixture factory for a SENT {@see Invitation} with a KNOWN token digest, so a feature can present a
 * matching `<invitationId>.<secret>` and drive the accept flow deterministically. The digest is a precomputed
 * `sha256(secret)` (the aggregate rehydrates it via {@see SingleUseToken::fromHash()}); the raw secret lives
 * only in the feature file, never here.
 *
 * The setup events stay on the aggregate for {@see Processor\RecordSeededDomainEventsProcessor}
 * to append: the invitation arrives as if minted in an earlier transaction, and an earlier transaction would
 * have left its events in the log. Draining them here instead is what silently exempted this aggregate from
 * the seeded history — a partial log nothing announces, which is the failure the seed exists to prevent.
 */
final class InvitationFixtureFactory
{
    public static function create(
        string $id,
        User $invitedUser,
        Organization $organization,
        string $tokenHash,
        string $expiresAt,
    ): Invitation {
        $invitedUserId = $invitedUser->getId() ?? throw new LogicException('Fixture user must have an id.');
        $organizationId = $organization->getId() ?? throw new LogicException('Fixture organization must have an id.');

        $token = SingleUseToken::fromHash($tokenHash, new DateTimeImmutable($expiresAt));
        $invitation = Invitation::create($id, $organizationId, $invitedUserId, $token);
        $invitation->markSent();

        return $invitation;
    }
}
