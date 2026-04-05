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
        $hub->expects(self::once())
            ->method('publish')
            ->with(self::callback(static function (Update $update): bool {
                return [MercureDemoTopic::URI] === $update->getTopics()
                    && str_contains($update->getData(), 'Mercure demo publish')
                    && false === $update->isPrivate();
            }));

        $controller = new MercurePublishDemoController($hub, 'dev');
        $response = $controller();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"published":true}', $response->getContent());
    }

    public function testInvokeNotFoundInProd(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');

        $controller = new MercurePublishDemoController($hub, 'prod');

        $this->expectException(NotFoundHttpException::class);
        $controller();
    }
}
