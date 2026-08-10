<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Context\Fixtures;

use Erpify\Tests\Behat\Context\MessengerConsumerContext;
use Erpify\Tests\Behat\Context\SymfonyCommandContext;
use Erpify\Tests\Behat\Support\Execution\LastRun;
use Erpify\Tests\Behat\Support\Messenger\MessengerTransports;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The two contexts that start something, assembled far enough to reach the steps that refuse.
 *
 * A refusal touches none of their collaborators, so a bare container and two stubs are all it takes —
 * and holding that here rather than in each test keeps a test class coupled to one thing instead of
 * eight, which is also the difference between one definition of the assembly and two drifting copies.
 */
final class RunContextFactory
{
    /**
     * @param Stub&MessageBusInterface      $messageBus
     * @param Stub&HandlersLocatorInterface $handlersLocator
     */
    public static function messenger(
        MessageBusInterface $messageBus,
        HandlersLocatorInterface $handlersLocator,
    ): MessengerConsumerContext {
        return new MessengerConsumerContext(
            new LastRun(),
            new MessengerTransports(new Container()),
            $messageBus,
            $handlersLocator,
        );
    }

    public static function console(KernelInterface $kernel): SymfonyCommandContext
    {
        return new SymfonyCommandContext(new LastRun(), $kernel);
    }
}
