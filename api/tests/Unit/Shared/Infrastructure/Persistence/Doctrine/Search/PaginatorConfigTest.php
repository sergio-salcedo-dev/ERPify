<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Persistence\Doctrine\Search;

use Erpify\Shared\Domain\Search\PaginationMode;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\PaginatorConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PaginatorConfig::class)]
final class PaginatorConfigTest extends TestCase
{
    #[Test]
    public function itDefaultsToLightModeWithFetchJoinCollection(): void
    {
        $config = new PaginatorConfig();

        $this->assertSame(PaginationMode::LIGHT, $config->paginationMode);
        $this->assertTrue($config->fetchJoinCollection);
    }

    #[Test]
    public function itAcceptsExplicitOverrides(): void
    {
        $config = new PaginatorConfig(PaginationMode::DETAILED, false);

        $this->assertSame(PaginationMode::DETAILED, $config->paginationMode);
        $this->assertFalse($config->fetchJoinCollection);
    }
}
