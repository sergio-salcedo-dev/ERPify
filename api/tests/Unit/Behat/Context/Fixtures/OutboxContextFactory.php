<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Context\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Tests\Behat\Context\OutboxContext;
use Erpify\Tests\Behat\NodeModifier\NodeModifierLocator;
use Erpify\Tests\Behat\NodeModifier\Scalar\NullNodeModifier;
use Erpify\Tests\Behat\NodeModifier\Scalar\StringNodeModifier;
use Erpify\Tests\Behat\Support\Messenger\MessengerTransports;
use Erpify\Tests\Behat\Support\Messenger\Outbox;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * An {@see OutboxContext} over a hand-built container, holding whatever events a test wants pending.
 *
 * Assembling it takes eight collaborators — the container, the transport double, the transport
 * resolution, the outbox, the modifier locator and two modifiers. Left inline it made every test class
 * that drives the context couple to all of them; here it is one dependency each, and one definition of
 * "how the outbox is stood up". The bus and the entity manager stay parameters because no test here
 * exercises the step that uses them, so each caller supplies whatever double it already has.
 *
 * Both queues are registered because {@see Outbox} reads every inspectable queue and refuses one that
 * resolves to nothing — the refusal is deliberate there, and a fixture that provoked it would be
 * testing the fixture.
 */
final class OutboxContextFactory
{
    public static function holding(
        MessageBusInterface $messageBus,
        EntityManagerInterface $entityManager,
        object ...$events,
    ): OutboxContext {
        $async = new InMemoryTransport();

        foreach ($events as $event) {
            $async->send(new Envelope($event));
        }

        $container = new Container();
        $container->set('messenger.transport.async', $async);
        $container->set('messenger.transport.failed', new InMemoryTransport());

        $context = new OutboxContext(
            new Outbox(new MessengerTransports($container)),
            $messageBus,
            $entityManager,
        );
        $context->setNodeModifierLocator(
            new NodeModifierLocator([new NullNodeModifier(), new StringNodeModifier()]),
        );

        return $context;
    }
}
