<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Persistence\Infrastructure\RequestTransactionBoundaryListener;
use Erpify\Tests\Functional\Shared\Persistence\Fixtures\ProbesATransactionScopedLock;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Drives the request boundary through the real kernel, with the dev/test connection reset and every other listener
 * wired as they ship: a route that leaves its transaction open fails the request that made it, its lock is released
 * on the server, and the next request on the same kernel commits on the connection it inherits.
 *
 * The kernel is not rebooted between requests, which is how a FrankenPHP worker keeps one connection across them.
 *
 * @internal
 */
#[CoversClass(RequestTransactionBoundaryListener::class)]
final class LeakedTransactionKernelBrowserTest extends WebTestCase
{
    use ProbesATransactionScopedLock;

    private KernelBrowser $client;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);
        $this->openBothConnections($connection);
    }

    protected function tearDown(): void
    {
        $this->closeBothConnections();
        parent::tearDown();
    }

    #[Test]
    public function aRouteThatLeaksFailsItsRequestAndTheNextRequestCommitsOverAReleasedLock(): void
    {
        $thrown = null;

        try {
            $this->client->request('GET', '/api/test/_leak-transaction');
        } catch (LogicException $logicException) {
            $thrown = $logicException;
        }

        $this->assertInstanceOf(LogicException::class, $thrown, 'a leaking route must fail the request that made it');
        $this->assertStringContainsString('"test_leak_transaction" left 1', $thrown->getMessage());
        $this->assertSame(0, $this->connection->getTransactionNestingLevel());
        $this->assertTrue($this->lockIsFreeOutside(), 'the leaked transaction still holds its lock on the server');

        $this->client->request('GET', '/api/test/_leak-transaction?commit=1');

        $this->assertResponseStatusCodeSame(204);
        $this->assertSame(0, $this->connection->getTransactionNestingLevel());
        $this->assertTrue($this->lockIsFreeOutside(), 'the committing request did not release its lock');
    }
}
