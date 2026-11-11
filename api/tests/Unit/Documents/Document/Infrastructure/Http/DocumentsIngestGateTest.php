<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Http;

use Erpify\Documents\Document\Infrastructure\Http\DocumentsIngestGate;
use Erpify\Documents\Document\Infrastructure\Http\UploadRoutes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @internal
 */
#[CoversClass(DocumentsIngestGate::class)]
final class DocumentsIngestGateTest extends TestCase
{
    #[DataProvider('provideIngestRoutes')]
    public function testBothRoutesAnswer404WhileIngestIsDisabled(string $route): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new DocumentsIngestGate(false))->onRequest($this->eventFor($route));
    }

    #[DataProvider('provideIngestRoutes')]
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testBothRoutesPassWhileIngestIsEnabled(string $route): void
    {
        (new DocumentsIngestGate(true))->onRequest($this->eventFor($route));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideIngestRoutes(): iterable
    {
        yield 'declare' => [UploadRoutes::DECLARE];
        yield 'content' => [UploadRoutes::CONTENT];
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testAnotherRouteIsNeverTouched(): void
    {
        (new DocumentsIngestGate(false))->onRequest($this->eventFor('backoffice_bank_account_create'));
    }

    private function eventFor(string $route): RequestEvent
    {
        $request = new Request();
        $request->attributes->set('_route', $route);

        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
