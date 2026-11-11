<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Event;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ConnectionRegistry;
use Erpify\Shared\Event\Infrastructure\Messenger\AsyncTransportValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransportFactory;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * The transport Messenger's own factory builds from the DSN the compose files set is on the connection the ORM
 * writes through — the identity `AsyncTransportValidator` compares, and the one fact the hand-built transport
 * of `AsyncDoctrineTransportFunctionalTest` cannot show, since it is handed its connection.
 *
 * The DSN is the one `compose.yaml` interpolates (`doctrine://default?auto_setup=0`), on a queue of its own so
 * nothing here can touch `async`.
 *
 * @internal
 */
#[CoversClass(AsyncTransportValidator::class)]
final class AsyncTransportFactoryResolutionFunctionalTest extends KernelTestCase
{
    #[Test]
    public function theTransportTheFactoryBuildsFromTheDeploymentDsnIsOnTheApplicationConnection(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get('doctrine');
        $this->assertInstanceOf(ConnectionRegistry::class, $registry);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $factory = new DoctrineTransportFactory($registry);
        $transport = $factory->createTransport(
            'doctrine://default?auto_setup=0',
            ['queue_name' => 'async_factory_resolution_probe'],
            new PhpSerializer(),
        );

        $validator = new AsyncTransportValidator(
            new ServiceLocator([AsyncTransportValidator::TRANSPORT => static fn (): DoctrineTransport => $transport]),
            $entityManager,
        );

        $this->assertNull($validator->problem());
    }
}
