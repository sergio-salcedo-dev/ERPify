<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Http;

use Erpify\Documents\Document\Infrastructure\Http\DiscardUploadSpoolListener;
use Erpify\Documents\Document\Infrastructure\Http\UploadRoutes;
use Erpify\Documents\Document\Infrastructure\Upload\PhpUploadBodySpool;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @internal
 */
#[CoversClass(DiscardUploadSpoolListener::class)]
final class DiscardUploadSpoolListenerTest extends TestCase
{
    private string $directory;

    #[Override]
    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/spool-listener-' . \bin2hex(\random_bytes(6));
        \mkdir($this->directory, 0o700, true);
    }

    #[Override]
    protected function tearDown(): void
    {
        \array_map(unlink(...), \glob($this->directory . '/*') ?: []);
        \rmdir($this->directory);
    }

    public function testItLeavesAnotherRoutesSpoolAlone(): void
    {
        $spool = $this->directory . '/phpUnrelated';
        \file_put_contents($spool, \random_bytes(PhpUploadBodySpool::SPOOL_THRESHOLD_BYTES + 1));

        $this->listener($budget = new RecordingTimeBudget())->onResponse(
            $this->eventFor('some_other_route', HttpKernelInterface::MAIN_REQUEST),
        );

        $this->assertFileExists($spool);
        $this->assertSame([], $budget->armed, 'a route that is not the upload never re-arms the time limit');
    }

    public function testItLeavesASubRequestAlone(): void
    {
        $this->listener($budget = new RecordingTimeBudget())->onResponse(
            $this->eventFor(UploadRoutes::CONTENT, HttpKernelInterface::SUB_REQUEST),
        );

        $this->assertSame([], $budget->armed);
    }

    public function testItRunsLastOfTheResponseListeners(): void
    {
        $attributes = (new ReflectionMethod(DiscardUploadSpoolListener::class, 'onResponse'))
            ->getAttributes(AsEventListener::class)
        ;
        $this->assertCount(1, $attributes);
        $listener = $attributes[0]->newInstance();

        $this->assertSame(KernelEvents::RESPONSE, $listener->event);
        $this->assertLessThan(0, $listener->priority, 'nothing after it may read the body again');
    }

    public function testAnUploadWithNoBodyLeavesTheDirectoryAsItWas(): void
    {
        $this->listener(new RecordingTimeBudget())->onResponse(
            $this->eventFor(UploadRoutes::CONTENT, HttpKernelInterface::MAIN_REQUEST),
        );

        $this->assertSame([], \glob($this->directory . '/*'));
    }

    private function listener(RecordingTimeBudget $budget): DiscardUploadSpoolListener
    {
        return new DiscardUploadSpoolListener(new PhpUploadBodySpool(new NullLogger(), $this->directory), $budget);
    }

    private function eventFor(string $route, int $type): ResponseEvent
    {
        $request = new Request();
        $request->attributes->set('_route', $route);

        return new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            $type,
            new Response(),
        );
    }
}
