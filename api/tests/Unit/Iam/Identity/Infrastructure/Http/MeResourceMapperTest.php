<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Identity\Infrastructure\Http\MeResourceMapper;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(MeResourceMapper::class)]
final class MeResourceMapperTest extends TestCase
{
    public function testItMapsIdEmailAndTheDomainRoleNamesWithoutTheRolePrefix(): void
    {
        $user = new SecurityUser(UserMother::create(roles: [Role::ADMIN, Role::AUDIT_READER]));

        $resource = (new MeResourceMapper())->toResource($user);

        $this->assertSame(UserMother::DEFAULT_ID, $resource->id);
        $this->assertSame(UserMother::DEFAULT_EMAIL, $resource->email);
        $this->assertSame(['ADMIN', 'AUDIT_READER'], $resource->roles);
    }
}
