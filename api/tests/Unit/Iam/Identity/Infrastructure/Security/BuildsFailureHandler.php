<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Exception as DbalException;
use Erpify\Iam\Identity\Application\LoginAttemptRegistrar;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Identity\Infrastructure\Security\ProblemDetailsAuthenticationFailureHandler;
use Erpify\Tests\Unit\Iam\Identity\Application\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Application\RecordingEventBus;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Symfony\Component\HttpFoundation\Request;

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
        );

        return new ProblemDetailsAuthenticationFailureHandler($registrar);
    }

    /**
     * A handler whose persistence of the failed attempt throws a store fault (a {@see DbalException}), so a test
     * can assert the record path absorbs it — never leaking it as a 500 or a resolved-vs-unknown enumeration
     * oracle — while the graded response still stands.
     */
    private function handlerFailingToPersist(): ProblemDetailsAuthenticationFailureHandler
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findByEmail')->willReturn(UserMother::create());
        $repository->method('save')->willThrowException($this->createStub(DbalException::class));

        return $this->handler($repository);
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
