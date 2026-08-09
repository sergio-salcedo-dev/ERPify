<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Erpify\Iam\Identity\Application\StoredIdentityProbeFailed;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DbalStoredIdentityIntegrity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The half of the adapter a real database will not perform on demand: a read that fails, and a read that
 * succeeds while returning something that is not an answer.
 *
 * Both matter for one reason. `0` and `[]` are the values that mean CLEAN, so an adapter that degraded to
 * either on a surprise would turn "the database could not be questioned" into "the table is fine" — and the
 * caller has an exit code, `INVALID`, that exists precisely to keep those apart. A test that only ever sees
 * healthy reads cannot tell the two designs apart.
 *
 * The `Connection` is stubbed rather than faked in-tree: it is the outer boundary this adapter is written
 * against, which is the one place this repository sanctions standing in for a collaborator — and a stub, not
 * a mock, because what is under test is the adapter's answer, never the calls it makes.
 *
 * @internal
 */
#[CoversClass(DbalStoredIdentityIntegrity::class)]
final class DbalStoredIdentityIntegrityFailureTest extends TestCase
{
    #[Test]
    public function itReachesNoVerdictWhenTheRolesReadFails(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willThrowException($this->driverFailure());

        $this->expectException(StoredIdentityProbeFailed::class);
        $this->expectExceptionMessageMatches('/identity_user\.roles/');

        (new DbalStoredIdentityIntegrity($connection))->roleValuesOutsideTheEnum();
    }

    #[Test]
    public function itReachesNoVerdictWhenACountReadFails(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willThrowException($this->driverFailure());

        $this->expectException(StoredIdentityProbeFailed::class);
        $this->expectExceptionMessageMatches('/identity_user\.password_hash/');

        (new DbalStoredIdentityIntegrity($connection))->identitiesWithAnUnreadableCredential();
    }

    #[Test]
    public function itRefusesARolesResultThatIsNotAListOfValues(): void
    {
        // Never filtered away to an empty list: discarding what the read could not explain is how a probe
        // comes to answer "nothing to report" from a result nobody understood.
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([['GHOST_ROLE']]);

        $this->expectException(StoredIdentityProbeFailed::class);
        $this->expectExceptionMessageMatches('/not a count|identity_user\.roles/');

        (new DbalStoredIdentityIntegrity($connection))->roleValuesOutsideTheEnum();
    }

    #[Test]
    public function itRefusesACountThatIsNotANumber(): void
    {
        // NOT degraded to zero, which is the value that means "clean": answering it from a result nobody
        // could interpret would turn an unreadable probe into a clean bill of health.
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('not-a-count');

        $this->expectException(StoredIdentityProbeFailed::class);
        $this->expectExceptionMessageMatches('/not a count/');

        (new DbalStoredIdentityIntegrity($connection))->identitiesWithMalformedRoles();
    }

    /**
     * `Doctrine\DBAL\Exception` is an interface, so the cheapest honest stand-in for "the driver failed" is a
     * throwable that implements it — no mock of a `Throwable`, whose message and code are final and cannot be
     * configured anyway.
     */
    private function driverFailure(): DbalException&Throwable
    {
        return new class ('the connection is gone') extends RuntimeException implements DbalException {
        };
    }
}
