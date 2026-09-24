<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Exception as DbalException;
use Erpify\Iam\Identity\Application\LoginAttemptRegistrar;
use Erpify\Iam\Identity\Application\RecordLockoutAuditBestEffort;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Identity\Infrastructure\Security\ProblemDetailsAuthenticationFailureHandler;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\RecordingEventBus;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Assembles the failure handler around a real {@see LoginAttemptRegistrar} composed with in-memory fakes, so
 * the handler test asserts behaviour through the true collaborator while keeping its own coupling lean.
 */
trait BuildsFailureHandler
{
    private function handler(UserRepository $repository): ProblemDetailsAuthenticationFailureHandler
    {
        $registrar = new LoginAttemptRegistrar(
            $repository,
            new RecordingEventBus(),
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable('2026-07-11T12:00:00+00:00')),
            new RecordLockoutAuditBestEffort(new RecordingAuditLogger(), new NullLogger()),
        );

        return new ProblemDetailsAuthenticationFailureHandler($registrar);
    }

    /**
     * A handler whose persistence of the failed attempt throws a store fault (a {@see DbalException}), so a test
     * can assert the record path absorbs it — never leaking it as a 500 or a resolved-vs-unknown enumeration
     * oracle — while the graded response still stands. The identity resolves on the LOCKED read too, since that
     * is the one the failure path decides on; stubbing only the unlocked one never reaches `save()`.
     */
    private function handlerFailingToPersist(): ProblemDetailsAuthenticationFailureHandler
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findByEmail')->willReturn(UserMother::create());
        $repository->method('findByEmailForUpdate')->willReturn(UserMother::create());
        $repository->method('save')->willThrowException($this->createStub(DbalException::class));

        return $this->handler($repository);
    }

    /**
     * A handler whose unit of work fails the way the transaction manager translates a deadlock, a lock timeout
     * or a referential fault — faults only an identity that EXISTS can meet, since only it locks a row and
     * writes.
     */
    private function handlerWhoseTransactionFails(Throwable $translated): ProblemDetailsAuthenticationFailureHandler
    {
        $transactionManager = $this->createStub(TransactionManager::class);
        $transactionManager->method('transactional')->willThrowException($translated);

        $registrar = new LoginAttemptRegistrar(
            new InMemoryUserRepository(UserMother::create()),
            new RecordingEventBus(),
            $transactionManager,
            new FixedClock(new DateTimeImmutable('2026-07-11T12:00:00+00:00')),
            new RecordLockoutAuditBestEffort(new RecordingAuditLogger(), new NullLogger()),
        );

        return new ProblemDetailsAuthenticationFailureHandler($registrar);
    }

    private function loginRequest(string $email): Request
    {
        return Request::create(
            '/login',
            Request::METHOD_POST,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) \json_encode(['email' => $email]),
        );
    }
}
