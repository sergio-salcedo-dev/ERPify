<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\PermissionCatalog;
use Erpify\Tests\Support\ConstantValueTokens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The catalog's twin of {@see StaticAuthorizationPolicyIsDataOnlyTest}: a central registry earns its keep only
 * while it stays a declaration. The moment it grows a branch — a permission conjured from config, an entry
 * computed per environment — the vocabulary stops being readable in one glance and starts being a behaviour
 * with its own bugs. Needing logic here means the design moved, not that this gate should relax.
 *
 * @internal
 */
#[CoversClass(PermissionCatalog::class)]
final class PermissionCatalogIsDataOnlyTest extends TestCase
{
    public function testTheCatalogConstantIsPureData(): void
    {
        $valueTokens = ConstantValueTokens::of(PermissionCatalog::class, 'PERMISSIONS');

        $this->assertNotEmpty($valueTokens, 'Constant PERMISSIONS was not found in the catalog source.');

        foreach ($valueTokens as $valueToken) {
            $this->assertFalse(ConstantValueTokens::isExecutable($valueToken), \sprintf(
                'The permission catalog must be literal data, but found an executable token: %s',
                ConstantValueTokens::describe($valueToken),
            ));
        }
    }
}
