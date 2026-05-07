<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Doctrine;

use Erpify\Tests\Doctrine\TestDebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class TestDebugDataHolderWiringTest extends KernelTestCase
{
    public function testServicesTestYamlAliasesDefaultHolderToOurs(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $resolved = $container->get(DebugDataHolder::class);

        $this->assertInstanceOf(TestDebugDataHolder::class, $resolved);
    }
}
