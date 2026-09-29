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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Pins what a unit of work that forgot its transaction costs the NEXT one on the same connection: nothing.
 * Every case asserts the consequence through {@see ProbesATransactionScopedLock}, not the nesting counter.
 *
 * @internal
 */
#[CoversClass(RequestTransactionBoundaryListener::class)]
final class RequestTransactionBoundaryListenerTest extends KernelTestCase
{
    use ProbesATransactionScopedLock;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
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
    public function aRequestThatLeftTwoLevelsOpenIsRolledBackOnTerminateAndFailsTheTest(): void
    {
        $listener = $this->requestBoundary();
        $request = Request::create('/api/v1/anything');
        $request->attributes->set('_route', 'leaky_route');

        $kernel = $this->httpKernel();

        $listener->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->connection->beginTransaction();
        $this->takeTheLock();
        $this->connection->beginTransaction();

        try {
            $listener->onTerminate(new TerminateEvent($kernel, $request, new Response()));
            $this->fail('A leak under the kernel browser must fail the test that caused it.');
        } catch (LogicException $logicException) {
            $this->assertStringContainsString('"leaky_route" left 2', $logicException->getMessage());
        }

        $this->assertSame(0, $this->connection->getTransactionNestingLevel());
        $this->assertTrue($this->lockIsFreeOutside(), 'the leaked transaction still holds its lock');
    }

    #[Test]
    public function aRequestThatClosedItsTransactionsPassesUntouched(): void
    {
        $listener = $this->requestBoundary();
        $request = Request::create('/api/v1/anything');
        $kernel = $this->httpKernel();

        $listener->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->connection->beginTransaction();
        $this->connection->commit();

        $listener->onTerminate(new TerminateEvent($kernel, $request, new Response()));

        $this->assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    #[Test]
    public function theRollbackRunsBeforeEveryTerminateListenerThatWrites(): void
    {
        $dispatcher = $this->dispatcher();
        $ours = null;
        $others = [];

        foreach ($dispatcher->getListeners(KernelEvents::TERMINATE) as $listener) {
            $priority = $dispatcher->getListenerPriority(KernelEvents::TERMINATE, $listener);
            $owner = \is_array($listener) ? $listener[0] : $listener;

            if ($owner instanceof RequestTransactionBoundaryListener) {
                $ours = $priority;

                continue;
            }

            $others[] = $priority;
        }

        $this->assertNotNull($ours, 'the terminate boundary is not registered');
        $this->assertNotSame([], $others, 'no other terminate listener: the ordering assertion would be vacuous');
        $this->assertGreaterThan(\max($others), $ours);
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function requestBoundary(): RequestTransactionBoundaryListener
    {
        $listener = self::getContainer()->get(RequestTransactionBoundaryListener::class);
        $this->assertInstanceOf(RequestTransactionBoundaryListener::class, $listener);

        return $listener;
    }

    private function httpKernel(): HttpKernelInterface
    {
        $kernel = self::$kernel;
        $this->assertInstanceOf(HttpKernelInterface::class, $kernel);

        return $kernel;
    }
}
