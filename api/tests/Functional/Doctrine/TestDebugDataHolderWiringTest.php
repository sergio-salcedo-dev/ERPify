<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Doctrine;

use Erpify\Tests\Doctrine\TestDebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TestDebugDataHolderWiringTest extends KernelTestCase
{
    public function testServicesTestYamlAliasesDefaultHolderToOurs(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $resolved = $container->get(DebugDataHolder::class);

        self::assertInstanceOf(TestDebugDataHolder::class, $resolved);
    }
}
