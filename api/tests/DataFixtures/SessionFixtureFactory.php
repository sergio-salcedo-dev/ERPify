<?php

declare(strict_types=1);

namespace Erpify\Tests\DataFixtures;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use LogicException;

/**
 * Alice fixture factory for {@see Session}. Seeds one live registry session per authenticatable Behat user with
 * a deterministic id, so a scenario that reloads fixtures mid-run restores the very session the Session
 * Admission Gate looks up (the id is stashed in the request session by {@see
 * \Erpify\Tests\Behat\Context\SecurityContext}). References the user and organization by id, mirroring
 * {@see MembershipFixtureFactory}.
 */
final class SessionFixtureFactory
{
    public static function createActive(string $sessionId, User $user, Organization $organization): Session
    {
        $userId = $user->getId() ?? throw new LogicException('Fixture user must have an id.');
        $organizationId = $organization->getId() ?? throw new LogicException('Fixture organization must have an id.');

        $session = Session::start(
            $sessionId,
            $userId,
            $organizationId,
            'Behat test client',
            '127.0.0.1',
            new DateTimeImmutable('+1 day'),
        );
        $session->pullDomainEvents();

        return $session;
    }
}
