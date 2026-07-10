<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Http;

use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Iam\Session\Infrastructure\Http\SessionResourceMapper;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SessionResourceMapper::class)]
final class SessionResourceMapperTest extends TestCase
{
    public function testItFlagsTheSessionMatchingTheCurrentCorrelationAsCurrent(): void
    {
        $session = SessionMother::active();

        $resource = (new SessionResourceMapper())->toResource(
            $session,
            SessionId::fromString(SessionMother::DEFAULT_ID),
        );

        $this->assertSame(SessionMother::DEFAULT_ID, $resource->id);
        $this->assertSame(SessionMother::DEFAULT_DEVICE, $resource->device);
        $this->assertTrue($resource->current);
    }

    public function testItLeavesOtherSessionsUnflagged(): void
    {
        $session = SessionMother::active();

        $resource = (new SessionResourceMapper())->toResource($session, SessionId::generate());

        $this->assertFalse($resource->current);
    }
}
