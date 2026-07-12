<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\LoginAttemptRegistrar;
use Erpify\Iam\Identity\Infrastructure\Security\ProblemDetailsAuthenticationFailureHandler;
use Erpify\Tests\Unit\Iam\Identity\Application\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\RecordingEventBus;
use Symfony\Component\HttpFoundation\Request;

/**
 * Assembles the failure handler around a real {@see LoginAttemptRegistrar} composed with in-memory fakes, so
 * the handler test asserts behaviour through the true collaborator while keeping its own coupling lean.
 */
trait BuildsFailureHandler
{
    private function handler(InMemoryUserRepository $repository): ProblemDetailsAuthenticationFailureHandler
    {
        $registrar = new LoginAttemptRegistrar(
            $repository,
            new RecordingEventBus(),
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable('2026-07-11T12:00:00+00:00')),
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
