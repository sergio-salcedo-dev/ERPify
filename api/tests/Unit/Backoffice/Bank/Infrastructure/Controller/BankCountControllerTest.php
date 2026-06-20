<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\Projection\BankCountReadModel;
use Erpify\Backoffice\Bank\Infrastructure\Controller\BankCountController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[CoversClass(BankCountController::class)]
final class BankCountControllerTest extends TestCase
{
    #[Test]
    public function itReturnsTheProjectedTotalAsJson(): void
    {
        $readModel = $this->createStub(BankCountReadModel::class);
        $readModel->method('total')->willReturn(7);

        $response = (new BankCountController($readModel))();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $this->assertJsonStringEqualsJsonString('{"total":7}', (string) $response->getContent());
    }
}
