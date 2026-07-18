<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Membership\Domain\Exception;

use Erpify\Organization\Membership\Domain\Exception\OrganizationNotProvisioned;
use Erpify\Shared\ErrorContract\Domain\Exception\ClientError;
use Erpify\Shared\ErrorContract\Domain\Exception\ServiceUnavailable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(OrganizationNotProvisioned::class)]
final class OrganizationNotProvisionedTest extends TestCase
{
    #[Test]
    public function itCarriesTheMissingOrganizationInvariant(): void
    {
        $exception = new OrganizationNotProvisioned();

        $this->assertSame('organization-not-provisioned', $exception->type());
        $this->assertSame(
            'The installation has no organization yet; provision it before granting a membership.',
            $exception->title(),
        );
    }

    #[Test]
    public function itIsMarkerLessSoTheContractKeepsItAServerError(): void
    {
        // No ClientError (4xx) and no ServiceUnavailable (503) marker: the RFC 9457 pipeline keeps it a 500 (a
        // broken server invariant), never a status the caller could correct.
        $reflection = new ReflectionClass(OrganizationNotProvisioned::class);
        $this->assertFalse($reflection->implementsInterface(ClientError::class));
        $this->assertFalse($reflection->implementsInterface(ServiceUnavailable::class));
    }
}
