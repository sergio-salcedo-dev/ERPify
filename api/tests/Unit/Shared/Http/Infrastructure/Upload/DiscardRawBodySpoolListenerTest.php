<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Http\Infrastructure\Upload;

use Erpify\Shared\Http\Infrastructure\Upload\DiscardRawBodySpoolListener;
use Erpify\Shared\Http\Infrastructure\Upload\PhpUploadBodySpool;
use Erpify\Shared\Http\Infrastructure\Upload\RawBodyRoute;
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
#[CoversClass(DiscardRawBodySpoolListener::class)]
#[CoversClass(RawBodyRoute::class)]
final class DiscardRawBodySpoolListenerTest extends TestCase
{
    private string $directory;

    #[Override]
    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/raw-spool-listener-' . \bin2hex(\random_bytes(6));
        \mkdir($this->directory, 0o700, true);
    }

    #[Override]
    protected function tearDown(): void
    {
        \array_map(unlink(...), \glob($this->directory . '/*') ?: []);
        \rmdir($this->directory);
    }

    public function testItLeavesARouteThatIsNotMarkedAlone(): void
    {
        $spool = $this->plantedSpool();

        $this->listener()->onResponse($this->eventFor(null, HttpKernelInterface::MAIN_REQUEST));

        $this->assertFileExists($spool);
    }

    public function testItLeavesARouteWhoseMarkIsNotTrueAlone(): void
    {
        $spool = $this->plantedSpool();

        $this->listener()->onResponse($this->eventFor('1', HttpKernelInterface::MAIN_REQUEST));

        $this->assertFileExists($spool);
    }

    public function testItLeavesASubRequestAlone(): void
    {
        $spool = $this->plantedSpool();

        $this->listener()->onResponse($this->eventFor(true, HttpKernelInterface::SUB_REQUEST));

        $this->assertFileExists($spool);
    }

    public function testAMarkedRouteWithNoBodyLeavesTheDirectoryAsItWas(): void
    {
        $spool = $this->plantedSpool();

        $this->listener()->onResponse($this->eventFor(true, HttpKernelInterface::MAIN_REQUEST));

        // `php://input` is empty here, so nothing read can match the planted file: a spool that is not this
        // request's is never destroyed.
        $this->assertFileExists($spool);
    }

    public function testAMarkedMainRequestHasItsDrainedBodyMatchedAndItsSpoolDestroyed(): void
    {
        $body = \random_bytes(PhpUploadBodySpool::SPOOL_THRESHOLD_BYTES * 3);
        $input = $this->directory . '/input.bin';
        \file_put_contents($input, $body);
        $ownSpool = $this->directory . '/phpOwn';
        \file_put_contents($ownSpool, $body);
        $anotherSpool = $this->directory . '/phpAnother';
        \file_put_contents($anotherSpool, \random_bytes(\strlen($body)));

        $this->listenerReading($input)->onResponse($this->eventFor(true, HttpKernelInterface::MAIN_REQUEST));

        $this->assertFileDoesNotExist($ownSpool, 'the spool holding exactly the drained bytes is destroyed');
        $this->assertFileExists($anotherSpool, "one that differs is another request's and stays");
    }

    public function testItRunsLastOfTheResponseListeners(): void
    {
        $attributes = (new ReflectionMethod(DiscardRawBodySpoolListener::class, 'onResponse'))
            ->getAttributes(AsEventListener::class)
        ;
        $this->assertCount(1, $attributes);
        $listener = $attributes[0]->newInstance();

        $this->assertSame(KernelEvents::RESPONSE, $listener->event);
        $this->assertLessThan(0, $listener->priority, 'nothing after it may read the body again');
    }

    private function plantedSpool(): string
    {
        $spool = $this->directory . '/phpUnrelated';
        \file_put_contents($spool, \random_bytes(PhpUploadBodySpool::SPOOL_THRESHOLD_BYTES + 1));

        return $spool;
    }

    private function listenerReading(string $input): DiscardRawBodySpoolListener
    {
        return new DiscardRawBodySpoolListener(
            new PhpUploadBodySpool(new NullLogger(), $this->directory),
            $input,
        );
    }

    private function listener(): DiscardRawBodySpoolListener
    {
        return new DiscardRawBodySpoolListener(new PhpUploadBodySpool(new NullLogger(), $this->directory));
    }

    private function eventFor(mixed $mark, int $type): ResponseEvent
    {
        $request = new Request();

        if (null !== $mark) {
            $request->attributes->set(RawBodyRoute::ATTRIBUTE, $mark);
        }

        return new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $type, new Response());
    }
}
