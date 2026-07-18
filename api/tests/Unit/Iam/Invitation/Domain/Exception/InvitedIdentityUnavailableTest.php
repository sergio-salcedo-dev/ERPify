<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Domain\Exception;

use Erpify\Iam\Invitation\Domain\Exception\InvitedIdentityUnavailable;
use Erpify\Shared\ErrorContract\Domain\Exception\ClientError;
use Erpify\Shared\ErrorContract\Domain\Exception\ServiceUnavailable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(InvitedIdentityUnavailable::class)]
final class InvitedIdentityUnavailableTest extends TestCase
{
    #[Test]
    public function withoutIdCarriesTheProvisioningInvariant(): void
    {
        $exception = InvitedIdentityUnavailable::withoutId();

        $this->assertSame('invited-identity-unavailable', $exception->type());
        $this->assertSame('The invited identity was provisioned without an id.', $exception->title());
    }

    #[Test]
    public function missingCarriesTheVanishedIdentityInvariant(): void
    {
        $exception = InvitedIdentityUnavailable::missing();

        $this->assertSame('invited-identity-unavailable', $exception->type());
        $this->assertSame('The invited identity no longer exists.', $exception->title());
    }

    #[Test]
    public function itIsMarkerLessSoTheContractKeepsItAServerError(): void
    {
        // No ClientError (4xx) and no ServiceUnavailable (503) marker: the RFC 9457 pipeline keeps it a 500 (an
        // internal integrity violation), never a status the caller could correct.
        $reflection = new ReflectionClass(InvitedIdentityUnavailable::class);
        $this->assertFalse($reflection->implementsInterface(ClientError::class));
        $this->assertFalse($reflection->implementsInterface(ServiceUnavailable::class));
    }
}
