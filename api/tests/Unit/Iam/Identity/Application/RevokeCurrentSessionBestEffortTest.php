<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\RevokeCurrentSessionBestEffort;
use Erpify\Iam\Session\Application\RevokeSession;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingCurrentSessionReference;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * The rollback half of the session teardown pair: it undoes the authentication one request produced and
 * leaves the identity's other sessions standing.
 *
 * The radius is the subject. Its sibling {@see \Erpify\Iam\Identity\Application\RevokeSessionsBestEffort}
 * takes every session because a credential change makes every one of them stale; this one runs while a
 * refusal is being raised over a session that was minted seconds earlier, and reaching past that session
 * lands on whoever else the identity has signed in — which, on the recovery channel, is the owner.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(RevokeCurrentSessionBestEffort::class)]
final class RevokeCurrentSessionBestEffortTest extends TestCase
{
    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    private const string ORGANIZATION_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4001';

    private const string NOW = '2026-08-28T12:00:00+00:00';

    #[Test]
    public function itRevokesTheSessionTheCorrelationNames(): void
    {
        $sessions = new InMemorySessionRepository();
        $current = $this->seed($sessions);

        $this->revoker($sessions, new RecordingCurrentSessionReference($current))->revoke();

        $this->assertSame([], $this->activeIds($sessions));
    }

    #[Test]
    public function itLeavesEveryOtherSessionOfTheIdentityStanding(): void
    {
        // The property the coarse sibling does not have, and the reason this class exists. Asserted as the
        // surviving SET rather than as a call count: a count cannot tell one revoke of the right session from
        // one revoke of all of them.
        $sessions = new InMemorySessionRepository();
        $other = $this->seed($sessions);
        $current = $this->seed($sessions);

        $this->revoker($sessions, new RecordingCurrentSessionReference($current))->revoke();

        $this->assertSame([$other->toString()], $this->activeIds($sessions));
    }

    #[Test]
    public function anAbsentCorrelationRevokesNothingAndSaysSo(): void
    {
        // Reported rather than treated as success, and deliberately not widened into a revoke by user id:
        // on the one path where the session is unknown, guessing reaches sessions this request never minted.
        $sessions = new InMemorySessionRepository();
        $survivor = $this->seed($sessions);
        $logger = new RecordingLogger();

        $this->revoker($sessions, new RecordingCurrentSessionReference(), $logger)->revoke();

        $this->assertSame([$survivor->toString()], $this->activeIds($sessions));
        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::WARNING, $logger->records[0]['level']);
    }

    #[Test]
    public function itSwallowsAndLogsAStoreFailureInsteadOfRaisingIt(): void
    {
        // It runs inside a `catch` that is answering a 400 or a 403 over work that already happened; letting
        // a session-store outage escape here would turn that refusal into a 500.
        $store = $this->createStub(SessionRepository::class);
        $store->method('findActiveById')->willThrowException(new RuntimeException('store down'));
        $logger = new RecordingLogger();

        $this->revoker($store, new RecordingCurrentSessionReference(SessionId::generate()), $logger)->revoke();

        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::WARNING, $logger->records[0]['level']);
    }

    #[Test]
    public function theSubjectIsAbsentFromEveryRecordItWrites(): void
    {
        // Both reporting branches go to the always-on `observability` channel, a sink no erasure path
        // reaches. The request's correlation id already ties the entry to its caller.
        $store = $this->createStub(SessionRepository::class);
        $store->method('findActiveById')->willThrowException(new RuntimeException('store down'));
        $logger = new RecordingLogger();
        $sessionId = SessionId::generate();

        $this->revoker($store, new RecordingCurrentSessionReference($sessionId), $logger)->revoke();
        $this->revoker($store, new RecordingCurrentSessionReference(), $logger)->revoke();

        $this->assertCount(2, $logger->records, 'a reporting branch went unexercised, so this asserts nothing');

        foreach ($logger->records as $record) {
            $encoded = \json_encode($record, JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $this->assertStringNotContainsString(self::USER_ID, $encoded);
            $this->assertStringNotContainsString($sessionId->toString(), $encoded);
        }
    }

    /**
     * @return list<string>
     */
    private function activeIds(InMemorySessionRepository $sessions): array
    {
        return \array_map(
            static fn (Session $session): string => $session->getId() ?? '',
            $sessions->findByUserId(self::USER_ID),
        );
    }

    private function seed(InMemorySessionRepository $sessions): SessionId
    {
        $sessionId = SessionId::generate();
        $session = Session::start(
            $sessionId->toString(),
            self::USER_ID,
            self::ORGANIZATION_ID,
            'test-device',
            null,
            (new DateTimeImmutable(self::NOW))->modify('+7 days'),
        );
        $session->pullDomainEvents();
        $sessions->save($session);

        return $sessionId;
    }

    private function revoker(
        SessionRepository $sessions,
        RecordingCurrentSessionReference $current,
        ?LoggerInterface $logger = null,
    ): RevokeCurrentSessionBestEffort {
        return new RevokeCurrentSessionBestEffort(
            $current,
            new RevokeSession($sessions, new RecordingEventBus(), new InlineTransactionManager()),
            $logger ?? new RecordingLogger(),
        );
    }
}
