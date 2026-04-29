<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Forces a fresh Doctrine connection at the start of every main request
 * in dev and test, so connections aren't long-lived across the
 * FrankenPHP worker's request loop.
 *
 * This makes the worker tolerant of out-of-band events that invalidate
 * its DB session — the behat fixtures context's `DROP/CREATE DATABASE`
 * cycle being the motivating case. Without this, a session killed by
 * `pg_terminate_backend` leaves the worker with a stale PDO handle and
 * the next request 500s with `ConnectionLost`.
 *
 * Not loaded in prod (`#[When]` gates the service definition).
 */
#[When(env: 'dev')]
#[When(env: 'test')]
#[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
final readonly class DoctrineConnectionResetListener
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        foreach ($this->registry->getConnections() as $connection) {
            if ($connection instanceof Connection) {
                $connection->close();
            }
        }
    }
}
