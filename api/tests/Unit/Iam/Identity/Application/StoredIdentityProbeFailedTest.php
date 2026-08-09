<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\StoredIdentityProbeFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The type exists so a failed read can never be mistaken for a clean table. Both named constructors are
 * pinned on the same two properties: the message says which COLUMN could not be read, and it says nothing
 * about what that column holds — an id reaching a log from here would be the leak the control is written to
 * detect, committed by the control itself.
 *
 * @internal
 */
#[CoversClass(StoredIdentityProbeFailed::class)]
final class StoredIdentityProbeFailedTest extends TestCase
{
    private const string SUBJECT_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    #[Test]
    public function itNamesTheColumnItCouldNotReadAndKeepsTheCause(): void
    {
        $cause = new RuntimeException(\sprintf('connection to %s lost', self::SUBJECT_ID));

        $failure = StoredIdentityProbeFailed::reading('identity_user.roles', $cause);

        $this->assertStringContainsString('identity_user.roles', $failure->getMessage());
        $this->assertStringContainsString('no verdict', $failure->getMessage());
        // The cause is kept for whoever debugs the infrastructure, and NOT folded into the message: a driver
        // error can quote the parameters of the statement that failed.
        $this->assertSame($cause, $failure->getPrevious());
        $this->assertStringNotContainsString(self::SUBJECT_ID, $failure->getMessage());
    }

    #[Test]
    public function itRefusesToReadAnUncountableResultAsZero(): void
    {
        $failure = StoredIdentityProbeFailed::uncountableResultFrom('identity_user.password_hash');

        $this->assertStringContainsString('identity_user.password_hash', $failure->getMessage());
        $this->assertStringContainsString('not a count', $failure->getMessage());
        $this->assertNotInstanceOf(Throwable::class, $failure->getPrevious());
    }
}
