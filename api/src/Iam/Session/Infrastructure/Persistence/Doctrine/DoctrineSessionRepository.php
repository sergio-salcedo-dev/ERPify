<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Clock\Domain\Clock;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Session persistence by COMPOSITION (injected {@see EntityManagerInterface}, no `ServiceEntityRepository`),
 * mirroring {@see \Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DoctrineUserRepository}.
 *
 * Two adapter-specific concerns live here:
 *   - the temporal-validity predicate (`status = ACTIVE AND expires_at > now`) is pushed into SQL, so an
 *     expired session is never returned and caducity needs no persisted state or sweeper;
 *   - any DBAL failure on the gate's read (a lost connection, a statement timeout, an exhausted pool — all
 *     {@see DbalException}) is converted to the domain {@see SessionStoreUnavailable} (→ 503), so a store outage
 *     lets the gate fail closed instead of leaking a raw 500. The read is a fixed PK+status+expiry SELECT with
 *     no user-supplied DQL, so a DBAL exception here is always infrastructural — a 503 (which still reaches
 *     Sentry) is the honest outcome, never masking an application bug. The bulk revocations are directed DQL
 *     UPDATEs (no aggregate hydration, no per-row event).
 */
#[AsAlias(SessionRepository::class)]
final readonly class DoctrineSessionRepository implements SessionRepository
{
    private const string ACTIVE_STATUS_PREDICATE = 's.status = :active';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Clock $clock,
    ) {
    }

    #[Override]
    public function save(Session $session): void
    {
        $this->entityManager->persist($session);
        $this->entityManager->flush();
    }

    #[Override]
    public function findActiveById(SessionId $id): ?Session
    {
        try {
            $result = $this->entityManager->createQueryBuilder()
                ->select('s')
                ->from(Session::class, 's')
                ->where('s.id = :id')
                ->andWhere(self::ACTIVE_STATUS_PREDICATE)
                ->andWhere('s.expiresAt > :now')
                ->setParameter('id', $id->toString())
                ->setParameter('active', SessionStatus::ACTIVE->value)
                ->setParameter('now', $this->clock->now())
                ->getQuery()
                ->getOneOrNullResult()
            ;
        } catch (DbalException $dbalException) {
            throw SessionStoreUnavailable::storeUnreachable($dbalException);
        }

        return $result instanceof Session ? $result : null;
    }

    #[Override]
    public function findByUserId(string $userId): array
    {
        /** @phpstan-var list<Session> */
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Session::class, 's')
            ->where('s.userId = :userId')
            ->andWhere(self::ACTIVE_STATUS_PREDICATE)
            ->andWhere('s.expiresAt > :now')
            ->orderBy('s.createdAt', 'DESC')
            ->setParameter('userId', $userId)
            ->setParameter('active', SessionStatus::ACTIVE->value)
            ->setParameter('now', $this->clock->now())
            ->getQuery()
            ->getResult()
        ;
    }

    #[Override]
    public function revokeOthersForUser(string $userId, SessionId $currentSessionId): void
    {
        $this->bulkRevokeActive($userId, $currentSessionId);
    }

    #[Override]
    public function revokeAllForUser(string $userId): void
    {
        $this->bulkRevokeActive($userId, null);
    }

    /**
     * Directed UPDATE flipping every currently-active session of the user to `REVOKED` (optionally excluding
     * the one in hand). Runs as SQL without hydrating the aggregates — the bulk path never needs their events.
     */
    private function bulkRevokeActive(string $userId, ?SessionId $except): void
    {
        $now = $this->clock->now();

        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->update(Session::class, 's')
            ->set('s.status', ':revoked')
            ->set('s.revokedAt', ':now')
            ->set('s.updatedAt', ':now')
            ->where('s.userId = :userId')
            ->andWhere(self::ACTIVE_STATUS_PREDICATE)
            ->setParameter('revoked', SessionStatus::REVOKED->value)
            ->setParameter('active', SessionStatus::ACTIVE->value)
            ->setParameter('now', $now)
            ->setParameter('userId', $userId)
        ;

        if ($except instanceof SessionId) {
            $queryBuilder->andWhere('s.id != :current')->setParameter('current', $except->toString());
        }

        $queryBuilder->getQuery()->execute();
    }
}
