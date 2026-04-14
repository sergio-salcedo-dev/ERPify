<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Frontoffice\Mercure\Infrastructure\Controller;

use Erpify\Frontoffice\Mercure\Domain\MercureDemoTopic;
use Erpify\Frontoffice\Mercure\Infrastructure\Controller\MercurePublishDemoController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class MercurePublishDemoControllerTest extends TestCase
{
    public function testInvokePublishesInDev(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())
            ->method('publish')
            ->with(self::callback(static fn(Update $update): bool => [MercureDemoTopic::URI] === $update->getTopics()
                && str_contains($update->getData(), 'Mercure demo publish')
                && false === $update->isPrivate()));

        $mercurePublishDemoController = new MercurePublishDemoController($hub, 'dev');
        $jsonResponse = $mercurePublishDemoController();

        $this->assertSame(\Symfony\Component\HttpFoundation\Response::HTTP_OK, $jsonResponse->getStatusCode(), (string) $jsonResponse->getContent());
        $this->assertSame('{"published":true}', $jsonResponse->getContent());
    }

    public function testInvokeNotFoundInProd(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->never())->method('publish');

        $mercurePublishDemoController = new MercurePublishDemoController($hub, 'prod');

        $this->expectException(NotFoundHttpException::class);
        $mercurePublishDemoController();
    }
}
