<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Exception;

use Erpify\Iam\Identity\Domain\Exception\SelfTargetedActForbidden;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The audit trail records a self-targeted refusal by {@see SelfTargetedActForbidden} alone, so the failure mode
 * that interface does not remove by itself is a refusal that forgets to carry it: still a 409, and no longer a
 * row. The universe is derived from the tree — every `Self*Forbidden` domain exception under `src`, by the
 * naming the four refusals share — rather than listed, so a fifth one is held to the marker the day it exists.
 *
 * A green proves every exception so named carries the marker. It says nothing about a self-refusal named
 * otherwise, nor about one thrown for a target that is not the actor; the floor below keeps the walk itself
 * from going vacuous when a path moves.
 *
 * @internal
 */
#[CoversNothing]
final class SelfTargetedRefusalMarkerGateTest extends TestCase
{
    private const string SRC = __DIR__ . '/../../../../../../src';

    private const int KNOWN_REFUSALS = 4;

    public function testEverySelfForbiddenDomainExceptionCarriesTheAuditMarker(): void
    {
        $files = [
            ...(\glob(self::SRC . '/*/*/Domain/Exception/Self*Forbidden.php') ?: []),
            ...(\glob(self::SRC . '/*/Domain/Exception/Self*Forbidden.php') ?: []),
        ];

        // One more than the refusals: the marker's own name matches the pattern too, and is skipped below.
        $this->assertGreaterThanOrEqual(
            self::KNOWN_REFUSALS + 1,
            \count($files),
            'The tree walk found fewer refusals than exist.',
        );

        $srcLength = \strlen((string) \realpath(self::SRC));

        foreach ($files as $file) {
            $relative = \substr((string) \realpath($file), $srcLength + 1, -\strlen('.php'));
            $class = 'Erpify\\' . \str_replace('/', '\\', $relative);

            if (SelfTargetedActForbidden::class === $class) {
                continue;
            }

            $this->assertTrue(
                \is_subclass_of($class, SelfTargetedActForbidden::class),
                \sprintf(
                    '%s refuses a self-targeted act without implementing %s, so its refusal writes no audit row.',
                    $class,
                    SelfTargetedActForbidden::class,
                ),
            );
        }
    }
}
