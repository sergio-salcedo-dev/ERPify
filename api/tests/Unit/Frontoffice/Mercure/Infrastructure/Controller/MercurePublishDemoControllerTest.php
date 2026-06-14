<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Frontoffice\Mercure\Infrastructure\Controller;

use Erpify\Frontoffice\Mercure\Domain\MercureDemoTopic;
use Erpify\Frontoffice\Mercure\Infrastructure\Controller\MercurePublishDemoController;
use Erpify\Shared\Infrastructure\Clock\SymfonyClock;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * @internal
 */
#[CoversNothing]
final class MercurePublishDemoControllerTest extends TestCase
{
    public function testInvokePublishesInDev(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())
            ->method('publish')
            ->with($this->callback(static fn (Update $update): bool => [MercureDemoTopic::URI] === $update->getTopics()
                && \str_contains($update->getData(), 'Mercure demo publish')
                && false === $update->isPrivate()))
        ;

        $clock = new SymfonyClock(new MockClock());
        $mercurePublishDemoController = new MercurePublishDemoController($hub, $clock, 'dev');
        $jsonResponse = $mercurePublishDemoController();

        $this->assertSame(Response::HTTP_OK, $jsonResponse->getStatusCode(), (string) $jsonResponse->getContent());
        $this->assertSame('{"published":true}', $jsonResponse->getContent());
    }

    public function testInvokeNotFoundInProd(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->never())->method('publish');

        $clock = new SymfonyClock(new MockClock());
        $mercurePublishDemoController = new MercurePublishDemoController($hub, $clock, 'prod');

        $this->expectException(NotFoundHttpException::class);
        $mercurePublishDemoController();
    }
}
