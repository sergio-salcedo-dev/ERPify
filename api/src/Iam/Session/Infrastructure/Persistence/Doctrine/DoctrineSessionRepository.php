<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Infrastructure\Persistence\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
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
 *     expired session is never returned and caducity needs no persisted state. Caducity is decided per query;
 *     the retention sweep is a separate concern that only bounds how long a row which has already stopped
 *     being admissible keeps its `ip`/`device`;
 *   - any DBAL failure on either read (a lost connection, a statement timeout, an exhausted pool — all
 *     {@see DbalException}) is converted to the domain {@see SessionStoreUnavailable} (→ 503) by
 *     {@see convertingStoreFailure()}, so a store outage lets the gate fail closed instead of leaking a raw
 *     500. Both reads are fixed SELECTs with no user-supplied DQL, so a DBAL exception here is always
 *     infrastructural — a 503 (which still reaches Sentry) is the honest outcome, never masking an application
 *     bug. The bulk revocations are directed DQL UPDATEs (no aggregate hydration, no per-row event).
 */
#[AsAlias(SessionRepository::class)]
final readonly class DoctrineSessionRepository implements SessionRepository
{
    private const string USER_ID_FILTER = 's.userId = :userId';

    /**
     * The lifecycle half of admissibility, alone. It is a constant of its own rather than part of
     * {@see andWhereAdmissibleNow()} because the bulk revocation needs exactly this half and not the temporal
     * one: an UPDATE that also required `expires_at > now` would leave an expired-but-active row un-revoked,
     * which is a different set of rows from the one "revoke everything of this user" means.
     *
     * It is therefore shared by a read helper and a write, and that is the one edit to make carefully: broaden
     * it (`s.status IN (:states)`, the natural companion to a third status) and the revocation UPDATE silently
     * changes which rows it flips. Sharing buys agreement on what `ACTIVE` means; it does not license a change
     * of meaning from either side alone.
     */
    private const string ACTIVE_STATUS_FILTER = 's.status = :active';

    private const string UNEXPIRED_FILTER = 's.expiresAt > :now';

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
        $result = $this->convertingStoreFailure(function () use ($id): mixed {
            $queryBuilder = $this->entityManager->createQueryBuilder()
                ->select('s')
                ->from(Session::class, 's')
                ->where('s.id = :id')
                ->setParameter('id', $id->toString())
            ;

            return $this->andWhereAdmissibleNow($queryBuilder)
                ->getQuery()
                ->getOneOrNullResult()
            ;
        });

        return $result instanceof Session ? $result : null;
    }

    #[Override]
    public function findByUserId(string $userId): array
    {
        /** @phpstan-var list<Session> */
        return $this->convertingStoreFailure(function () use ($userId): mixed {
            $queryBuilder = $this->entityManager->createQueryBuilder()
                ->select('s')
                ->from(Session::class, 's')
                ->where(self::USER_ID_FILTER)
                // `created_at` is TIMESTAMP(0), so two sessions minted in the same second tie and the ordering
                // would be whatever the plan yields. The id breaks it: UUID v7 sorts by mint time, so it agrees
                // with "newest first" instead of merely making the sequence arbitrary-but-stable.
                ->orderBy('s.createdAt', 'DESC')
                ->addOrderBy('s.id', 'DESC')
                ->setParameter('userId', $userId)
            ;

            return $this->andWhereAdmissibleNow($queryBuilder)
                ->getQuery()
                ->getResult()
            ;
        });
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

    #[Override]
    public function deleteAllForUser(string $userId): int
    {
        $affected = $this->entityManager->createQueryBuilder()
            ->delete(Session::class, 's')
            ->where(self::USER_ID_FILTER)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute()
        ;

        return \is_int($affected) ? $affected : 0;
    }

    /**
     * One directed DELETE, whichever window elapses first. The branches are parenthesised explicitly: leaving
     * them unbracketed would read correctly only because `AND` binds tighter than `OR`, which makes the rule
     * depend on a precedence the next reader has to recall rather than on what the statement says.
     *
     * **Only the revocation branch names a status, and that asymmetry is the rule rather than an oversight.**
     * Revocation is a decision someone took, so it is meaningful only for a row carrying that state; expiry is
     * a property of the row's own clock and applies whatever its status. Keeping `status = ACTIVE` on the
     * expiry branch would have let a revocation *extend* a lapsed row's life — a bulk revocation stamps
     * `revokedAt = now` on rows already long past `expiresAt`, moving them onto the later clock — so a row
     * dead for months could earn another 30 days because someone changed their password. It also means a
     * status added later cannot quietly fall through both branches and keep its `ip`/`device` for ever.
     *
     * A `REVOKED` row whose `revokedAt` is NULL is never matched by the first branch, because
     * `NULL < :threshold` is unknown; the expiry branch still reaches it once its own clock runs out, so the
     * NULL is a delay rather than a leak. Neither writer can produce one anyway — {@see Session::revoke()}
     * and {@see bulkRevokeActive()} both stamp it.
     *
     * **Concurrency, named and accepted.** This is one unbounded statement with no ordering and no advisory
     * lock, unlike `audit_log`'s prune, which batches under both. `FulfilIdentityErasure` deletes this table's
     * rows through `PurgeUserSessions` inside its own transaction, so a sweep and an erasure can reach two
     * shared rows in opposite orders and deadlock. The exposure is bounded by frequency (one daily tick
     * against an operator action) and its outcome is a `40P01` surfacing as a retryable 503, never lost data.
     * **Revisit if** the sweep gains a second trigger, the erasure becomes automatic, or this table grows past
     * the point where one statement is a short one.
     */
    #[Override]
    public function deleteRetired(DateTimeImmutable $revokedBefore, DateTimeImmutable $expiredBefore): int
    {
        $affected = $this->entityManager->createQueryBuilder()
            ->delete(Session::class, 's')
            ->where('(s.status = :revoked AND s.revokedAt < :revokedBefore)')
            ->orWhere('(s.expiresAt < :expiredBefore)')
            ->setParameter('revoked', SessionStatus::REVOKED->value)
            ->setParameter('revokedBefore', $revokedBefore)
            ->setParameter('expiredBefore', $expiredBefore)
            ->getQuery()
            ->execute()
        ;

        return \is_int($affected) ? $affected : 0;
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
            ->where(self::USER_ID_FILTER)
            ->andWhere(self::ACTIVE_STATUS_FILTER)
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

    /**
     * Runs a read against the store and converts any DBAL failure — a lost connection, a statement timeout, an
     * exhausted pool, all {@see DbalException} — into the domain {@see SessionStoreUnavailable} (→ 503).
     *
     * Both reads go through it so neither can answer a store outage differently from the other: the gate's
     * admission read and the "my sessions" listing state one contract, and a conversion written per method is
     * one somebody can add to a second read and forget on a third.
     *
     * **It takes the whole read as a callable rather than guarding a body from outside, because what must be
     * guarded is the EXECUTION and nothing places it there but the caller.**
     * `EntityManager::createQueryBuilder()` only news up a `QueryBuilder`: it opens no connection and can raise
     * nothing this converts. The outage arises where the query runs — `getOneOrNullResult()` / `getResult()`,
     * which reach `EntityManager::createQuery()` and then the driver. Handing the read in means the execution
     * cannot end up outside the guard while the guard still looks present.
     *
     * @param callable(): mixed $read
     */
    private function convertingStoreFailure(callable $read): mixed
    {
        try {
            return $read();
        } catch (DbalException $dbalException) {
            throw SessionStoreUnavailable::storeUnreachable($dbalException);
        }
    }

    /**
     * Adds the full admissibility rule — `status = ACTIVE AND expires_at > now` — and its two bindings to a
     * builder the caller has already created. The two reads share it so a change to the rule (a grace window
     * on expiry, say) cannot land on one of them and not the other.
     *
     * It takes a builder rather than returning one because the two callers differ in what comes before this
     * clause: one selects by primary key, the other filters by user and imposes an order. Handing each its own
     * builder keeps that difference where it belongs and leaves this method with one job.
     *
     * The alias stays baked into the constants rather than threaded as a parameter — this adapter has exactly
     * one (`s`), which is the choice {@see USER_ID_FILTER} already encodes, and a parameter every call site
     * fills with the same literal buys nothing until a second alias exists.
     */
    private function andWhereAdmissibleNow(QueryBuilder $queryBuilder): QueryBuilder
    {
        return $queryBuilder
            ->andWhere(self::ACTIVE_STATUS_FILTER)
            ->andWhere(self::UNEXPIRED_FILTER)
            ->setParameter('active', SessionStatus::ACTIVE->value)
            ->setParameter('now', $this->clock->now())
        ;
    }
}
