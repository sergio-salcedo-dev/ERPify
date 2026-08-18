<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Messenger\Maintenance;

use Erpify\Iam\Identity\Application\InspectStoredIdentity;
use Erpify\Iam\Identity\Application\StoredIdentityProbeFailed;
use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\InspectStoredIdentityHandler;
use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\InspectStoredIdentityMessage;
use Erpify\Tests\Unit\Iam\Identity\Application\FailingStoredIdentityIntegrity;
use Erpify\Tests\Unit\Iam\Identity\Application\FixedStoredIdentityIntegrity;
use Erpify\Tests\Unit\Iam\Identity\Application\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The unattended arm of the stored-identity control. What it must get right is not the wording of a line but
 * three properties: it is silent on a clean table, it is loud at a level production can actually emit, and it
 * reports counts rather than the contents of the rows.
 *
 * @internal
 */
#[CoversClass(InspectStoredIdentityHandler::class)]
#[CoversClass(InspectStoredIdentity::class)]
final class InspectStoredIdentityHandlerTest extends TestCase
{
    #[Test]
    public function itStaysSilentWhenNothingHasDrifted(): void
    {
        $logger = new RecordingLogger();

        $this->handler(new FixedStoredIdentityIntegrity([], 0), $logger)(new InspectStoredIdentityMessage());

        // An alarm that speaks on every tick is one nobody reads by the second week.
        $this->assertSame([], $logger->records);
    }

    #[Test]
    public function itRaisesASingleErrorNamingEveryFactWhenSomethingHasDrifted(): void
    {
        $logger = new RecordingLogger();
        $integrity = new FixedStoredIdentityIntegrity(
            ['GHOST_ROLE'],
            2,
            malformedRoles: 1,
            admittedWithoutCredential: 3,
        );

        $this->handler($integrity, $logger)(new InspectStoredIdentityMessage());

        $this->assertCount(1, $logger->records);
        // `error` and not `warning`: the level states what the finding is — a divergence to act on — rather
        // than buying arrival, which the always-on `observability` channel provides at any level.
        $this->assertSame('error', $logger->records[0]['level']);
        $context = $logger->records[0]['context'];
        $this->assertSame(1, $context['orphanRoleValues'] ?? null, 'the COUNT of values, never the values');
        $this->assertSame(1, $context['malformedRoles'] ?? null);
        $this->assertSame(2, $context['unreadableCredentials'] ?? null);
        $this->assertSame(3, $context['admittedWithoutCredential'] ?? null);
        $this->assertSame('identity:integrity:inspect', $context['repairWith'] ?? null);
    }

    #[Test]
    public function itKeepsTheStoredValuesOutOfTheLog(): void
    {
        // The values are arbitrary text from a column an out-of-band write controls, and log storage has its
        // own retention and no declared owner of its erasure. They belong in the report an operator reaches
        // deliberately, not in a line a scheduler emits unattended.
        $logger = new RecordingLogger();
        $integrity = new FixedStoredIdentityIntegrity(['GHOST_ROLE', 'RETIRED'], 0);

        $this->handler($integrity, $logger)(new InspectStoredIdentityMessage());

        $emitted = \json_encode($logger->records, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('GHOST_ROLE', $emitted);
        // Both, because seeding a value and then watching only its neighbour is how a leak keeps a witness
        // that never looks at it.
        $this->assertStringNotContainsString('RETIRED', $emitted);
    }

    #[Test]
    public function itLetsAFailedProbeLeaveByTheOtherDoor(): void
    {
        // A finding is a data problem and stays a log line; a control that could not run is a software fault
        // and must reach the error tracker, which the Monolog bridge deliberately does not do for log lines.
        $logger = new RecordingLogger();

        $this->expectException(StoredIdentityProbeFailed::class);

        try {
            $this->handler(new FailingStoredIdentityIntegrity(), $logger)(new InspectStoredIdentityMessage());
        } finally {
            $this->assertSame([], $logger->records, 'a run that established nothing reports no finding');
        }
    }

    private function handler(
        FailingStoredIdentityIntegrity|FixedStoredIdentityIntegrity $integrity,
        RecordingLogger $logger,
    ): InspectStoredIdentityHandler {
        return new InspectStoredIdentityHandler(new InspectStoredIdentity($integrity), $logger);
    }
}
