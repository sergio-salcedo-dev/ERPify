<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Exception;

use Erpify\Iam\Identity\Domain\Exception\RecoverySecretAlreadyExists;
use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The refusal a second mint meets, and the one refusal on this channel that names its own remedy: an account
 * holds one recovery secret at a time, so the caller is told to revoke before minting rather than left to
 * infer it from a bare conflict.
 *
 * @internal
 */
#[CoversClass(RecoverySecretAlreadyExists::class)]
final class RecoverySecretAlreadyExistsTest extends TestCase
{
    #[Test]
    public function itCarriesItsOwnTypeAndNamesTheRemedy(): void
    {
        $exception = new RecoverySecretAlreadyExists();

        $this->assertSame('recovery-secret-already-exists', $exception->type());
        $this->assertSame(
            'This account already has a recovery secret. Revoke it before minting a new one.',
            $exception->title(),
        );
    }

    #[Test]
    public function itMapsToA409ThroughTheConflictMarker(): void
    {
        // The status is the caller's whole cue that retrying is pointless until they act, so the marker is
        // asserted rather than assumed from the class name.
        $this->assertTrue(
            (new ReflectionClass(RecoverySecretAlreadyExists::class))->implementsInterface(Conflict::class),
        );
    }
}
