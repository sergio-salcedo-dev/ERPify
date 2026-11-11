<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Event\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Event\Infrastructure\Messenger\AsyncTransportValidator;
use Erpify\Shared\Event\Infrastructure\Messenger\RequireTransactionalPublishMiddleware;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\PostgreSqlConnection;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * The production guard in front of Messenger's own sender middleware, with `async` resolving to a real Doctrine
 * transport on a queue of its own: the shape of `messenger.bus.default` minus the append to `event_store`,
 * which the probe has no use for. The transport is built the way the Doctrine factory builds it for
 * PostgreSQL, on whichever connection the case hands it — the ORM's own, or another that must be refused.
 *
 * @internal
 */
final readonly class AsyncProbeRig
{
    public const string QUEUE = 'async_publish_guard_probe';

    private DoctrineTransport $transport;

    public function __construct(private EntityManagerInterface $entityManager, Connection $transportConnection)
    {
        $this->transport = new DoctrineTransport(
            new PostgreSqlConnection(
                ['table_name' => 'messenger_messages', 'queue_name' => self::QUEUE, 'auto_setup' => false],
                $transportConnection,
            ),
            new PhpSerializer(),
        );
    }

    public function bus(): MessageBus
    {
        $senders = new SendersLocator(
            [AsyncProbeMessage::class => [AsyncTransportValidator::TRANSPORT]],
            $this->locator(),
        );

        return new MessageBus([
            new RequireTransactionalPublishMiddleware($this->entityManager, $senders, $this->validator()),
            new SendMessageMiddleware($senders),
        ]);
    }

    /**
     * A fresh validator, so the memoised verdict of one case never answers for another.
     */
    public function validator(): AsyncTransportValidator
    {
        return new AsyncTransportValidator($this->locator(), $this->entityManager);
    }

    public static function queued(Connection $connection): int
    {
        $count = $connection->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?', [self::QUEUE]);

        // -1 when the server answers no number: no case expects it, so it fails loudly instead of reading as zero.
        return \is_numeric($count) ? (int) $count : -1;
    }

    public static function purge(Connection $connection): void
    {
        $connection->executeStatement('DELETE FROM messenger_messages WHERE queue_name = ?', [self::QUEUE]);
    }

    /**
     * @return ServiceLocator<DoctrineTransport>
     */
    private function locator(): ServiceLocator
    {
        $transport = $this->transport;

        return new ServiceLocator([
            AsyncTransportValidator::TRANSPORT => static fn (): DoctrineTransport => $transport,
        ]);
    }
}
