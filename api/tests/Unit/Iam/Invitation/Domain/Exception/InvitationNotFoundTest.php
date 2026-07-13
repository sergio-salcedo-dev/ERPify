<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Domain\Exception;

use Erpify\Iam\Invitation\Domain\Exception\InvitationNotFound;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(InvitationNotFound::class)]
final class InvitationNotFoundTest extends TestCase
{
    private const string INVITATION_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5c10';

    #[Test]
    public function itIsANotFoundCarryingTheId(): void
    {
        $exception = new InvitationNotFound(self::INVITATION_ID);

        $this->assertTrue((new ReflectionClass(InvitationNotFound::class))->implementsInterface(NotFound::class));
        $this->assertSame('invitation-not-found', $exception->type());
        $this->assertSame(['invitationId' => self::INVITATION_ID], $exception->context());
    }
}
