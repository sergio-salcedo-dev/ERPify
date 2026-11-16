<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Images\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Event\Infrastructure\Bus\SymfonyMessengerEventBus;
use Erpify\Shared\Event\Infrastructure\Messenger\AsyncTransportValidator;
use Erpify\Shared\Event\Infrastructure\Messenger\RequireTransactionalPublishMiddleware;
use Erpify\Shared\Images\Domain\Event\ImageDeletionRequested;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\PostgreSqlConnection;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * The production publish guard in front of Messenger's own sender middleware, with `async` resolving to the REAL
 * Doctrine transport on the application's connection and a queue of its own — the shape of `messenger.bus.default`
 * minus the append to `event_store`, which a probe of the outbox has no use for. `async` is an in-memory double under
 * `when@test`, so nothing else in the suite sees the transport's `INSERT`.
 *
 * What was queued is read from a SECOND connection, because the application's own connection sees its uncommitted
 * rows and would answer "queued" in every case.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final readonly class ImageReleaseRig
{
    public const string QUEUE = 'async_image_attachment_probe';

    private DoctrineTransport $transport;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->transport = new DoctrineTransport(
            new PostgreSqlConnection(
                ['table_name' => 'messenger_messages', 'queue_name' => self::QUEUE, 'auto_setup' => false],
                $entityManager->getConnection(),
            ),
            new PhpSerializer(),
        );
    }

    public function eventBus(): EventBus
    {
        $locator = new ServiceLocator([
            AsyncTransportValidator::TRANSPORT => fn (): DoctrineTransport => $this->transport,
        ]);
        $senders = new SendersLocator(
            [ImageDeletionRequested::class => [AsyncTransportValidator::TRANSPORT]],
            $locator,
        );

        return new SymfonyMessengerEventBus(new MessageBus([
            new RequireTransactionalPublishMiddleware(
                $this->entityManager,
                $senders,
                new AsyncTransportValidator($locator, $this->entityManager),
            ),
            new SendMessageMiddleware($senders),
        ]));
    }

    /**
     * How many releases of this image the queue holds as seen from `$connection`.
     */
    public static function queuedFor(Connection $connection, string $imageId): int
    {
        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND body LIKE ?',
            [self::QUEUE, '%' . $imageId . '%'],
        );

        // -1 when the server answers no number: no case expects it, so it fails loudly instead of reading as zero.
        return \is_numeric($count) ? (int) $count : -1;
    }

    public static function purge(Connection $connection): void
    {
        $connection->executeStatement('DELETE FROM messenger_messages WHERE queue_name = ?', [self::QUEUE]);
    }
}
