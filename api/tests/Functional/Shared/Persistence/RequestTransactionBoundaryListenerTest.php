<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Persistence\Infrastructure\DoctrineConnectionResetListener;
use Erpify\Shared\Persistence\Infrastructure\LeakedTransactionContainment;
use Erpify\Shared\Persistence\Infrastructure\RequestTransactionBoundaryListener;
use Erpify\Tests\Functional\Shared\Persistence\Fixtures\ProbesATransactionScopedLock;
use Erpify\Tests\Functional\Shared\Persistence\Fixtures\RecordingLogger;
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

/**
 * Pins the HTTP boundary in both of its modes — the test lane, where a leak fails the test, and every other
 * environment, where it is rolled back from zero, reported, and never thrown — and the listener order that makes
 * each check see what it claims to. Every leak case reads a lock from a second connection.
 *
 * @internal
 */
#[CoversClass(RequestTransactionBoundaryListener::class)]
final class RequestTransactionBoundaryListenerTest extends KernelTestCase
{
    use ProbesATransactionScopedLock;

    private RecordingLogger $logger;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);
        $this->openBothConnections($connection);
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        $this->closeBothConnections();
        parent::tearDown();
    }

    #[Test]
    public function underTestALeakIsRolledBackAndFailsTheTestThatCausedIt(): void
    {
        $listener = $this->listener('test');
        $request = $this->request();

        $listener->onRequest($this->requestEvent($request));
        $this->connection->beginTransaction();
        $this->takeTheLock();
        $this->connection->beginTransaction();

        $thrown = null;

        try {
            $listener->onTerminate($this->terminateEvent($request));
        } catch (LogicException $logicException) {
            $thrown = $logicException;
        }

        $this->assertInstanceOf(LogicException::class, $thrown, 'a leak under the kernel browser must fail its test');
        $this->assertStringContainsString('"leaky_route" left 2', $thrown->getMessage());

        $this->assertSame(0, $this->connection->getTransactionNestingLevel());
        $this->assertTrue($this->lockIsFreeOutside(), 'the leaked transaction still holds its lock');
    }

    #[Test]
    public function outsideTestALeakIsRolledBackAndReportedWithoutThrowingOrNamingThePath(): void
    {
        $listener = $this->listener('prod');
        $request = $this->request();

        $listener->onRequest($this->requestEvent($request));
        $this->connection->beginTransaction();
        $this->takeTheLock();

        $listener->onTerminate($this->terminateEvent($request));

        $this->assertTrue($this->lockIsFreeOutside(), 'the leaked transaction still holds its lock');
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('critical', $this->logger->records[0]['level']);
        $this->assertSame(
            ['boundary' => 'http_request', 'route' => 'leaky_route', 'method' => 'GET', 'leaked_levels' => 1,
                'connection_closed' => false],
            $this->logger->records[0]['context'],
        );
    }

    #[Test]
    public function outsideTestALeakThatEscapedTheLastRequestIsRolledBackWhenTheNextOneStarts(): void
    {
        $listener = $this->listener('prod');
        $this->connection->beginTransaction();
        $this->takeTheLock();

        $listener->onRequest($this->requestEvent($this->request()));

        $this->assertTrue($this->lockIsFreeOutside(), 'the carried-over transaction still holds its lock');
        $this->assertSame('http_request_start', $this->logger->records[0]['context']['boundary'] ?? null);
    }

    #[Test]
    public function aLeakOpenedByALaterTerminateListenerIsCaughtByTheClosingPass(): void
    {
        $listener = $this->listener('prod');
        $request = $this->request();

        $listener->onRequest($this->requestEvent($request));
        $listener->onTerminate($this->terminateEvent($request));

        $this->connection->beginTransaction();
        $this->takeTheLock();
        $listener->onTerminateEnd($this->terminateEvent($request));

        $this->assertTrue($this->lockIsFreeOutside(), 'the terminate-phase leak still holds its lock');
        $this->assertSame('http_terminate', $this->logger->records[0]['context']['boundary'] ?? null);
    }

    #[Test]
    public function underTestARequestThatNeverReachedTheBaselineRollsNothingBack(): void
    {
        $listener = $this->listener('test');
        $this->connection->beginTransaction();

        $listener->onTerminate($this->terminateEvent($this->request()));

        $this->assertSame(1, $this->connection->getTransactionNestingLevel());
        $this->assertSame([], $this->logger->records);
    }

    #[Test]
    public function eachCheckIsRegisteredWhereItsClaimNeedsIt(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $ours = ['kernel.request' => [], 'kernel.terminate' => []];
        $others = ['kernel.request' => [], 'kernel.terminate' => []];

        foreach (\array_keys($ours) as $eventName) {
            foreach ($dispatcher->getListeners($eventName) as $listener) {
                $priority = $dispatcher->getListenerPriority($eventName, $listener);
                $owner = \is_array($listener) ? $listener[0] : $listener;

                if ($owner instanceof RequestTransactionBoundaryListener) {
                    $ours[$eventName][] = $priority;
                } elseif (!$owner instanceof DoctrineConnectionResetListener) {
                    $others[$eventName][] = $priority;
                }
            }
        }

        $this->assertSame([DoctrineConnectionResetListener::PRIORITY - 1], $ours['kernel.request']);
        $this->assertCount(2, $ours['kernel.terminate']);
        $this->assertNotSame([], $others['kernel.terminate'], 'no other terminate listener: vacuous ordering');
        $this->assertGreaterThan(\max($others['kernel.terminate']), \max($ours['kernel.terminate']));
        $this->assertLessThan(\min($others['kernel.terminate']), \min($ours['kernel.terminate']));
    }

    private function listener(string $environment): RequestTransactionBoundaryListener
    {
        return new RequestTransactionBoundaryListener(
            new LeakedTransactionContainment($this->connection, $this->logger),
            $environment,
        );
    }

    private function request(): Request
    {
        $request = Request::create('/api/v1/people/someone@example.com');
        $request->attributes->set('_route', 'leaky_route');

        return $request;
    }

    private function requestEvent(Request $request): RequestEvent
    {
        return new RequestEvent($this->httpKernel(), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function terminateEvent(Request $request): TerminateEvent
    {
        return new TerminateEvent($this->httpKernel(), $request, new Response());
    }

    private function httpKernel(): HttpKernelInterface
    {
        $kernel = self::$kernel;
        $this->assertInstanceOf(HttpKernelInterface::class, $kernel);

        return $kernel;
    }
}
