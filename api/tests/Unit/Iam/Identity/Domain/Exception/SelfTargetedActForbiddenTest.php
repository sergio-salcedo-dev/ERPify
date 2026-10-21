<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Exception;

use Erpify\Iam\Identity\Domain\Exception\SelfErasureForbidden;
use Erpify\Iam\Identity\Domain\Exception\SelfRoleChangeForbidden;
use Erpify\Iam\Identity\Domain\Exception\SelfStatusChangeForbidden;
use Erpify\Iam\Identity\Domain\Exception\SelfTargetedActForbidden;
use Erpify\Iam\Identity\Domain\Exception\SelfUnlockForbidden;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The audit listener records a self-targeted refusal by this marker alone, so a refusal that stops carrying it
 * is refused in silence — still a 409, and no longer a row. Each of the four is pinned here with the problem
 * type the row copies into its metadata.
 *
 * @internal
 */
#[CoversClass(SelfErasureForbidden::class)]
#[CoversClass(SelfRoleChangeForbidden::class)]
#[CoversClass(SelfStatusChangeForbidden::class)]
#[CoversClass(SelfUnlockForbidden::class)]
final class SelfTargetedActForbiddenTest extends TestCase
{
    private const string ACTOR_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66';

    #[DataProvider('provideEverySelfRefusalCarriesTheMarkerTheAuditListenerMatchesCases')]
    public function testEverySelfRefusalCarriesTheMarkerTheAuditListenerMatches(object $refusal, string $type): void
    {
        $this->assertInstanceOf(SelfTargetedActForbidden::class, $refusal);
        $this->assertSame($type, $refusal->type());
    }

    /**
     * @return iterable<string, array{object, string}>
     */
    public static function provideEverySelfRefusalCarriesTheMarkerTheAuditListenerMatchesCases(): iterable
    {
        yield 'role change' => [SelfRoleChangeForbidden::forActor(self::ACTOR_ID), 'self-role-change-forbidden'];

        yield 'status change' => [SelfStatusChangeForbidden::forActor(self::ACTOR_ID), 'self-status-change-forbidden'];

        yield 'unlock' => [SelfUnlockForbidden::forActor(self::ACTOR_ID), 'self-unlock-forbidden'];

        yield 'erasure' => [SelfErasureForbidden::forActor(self::ACTOR_ID), 'self-erasure-forbidden'];
    }
}
