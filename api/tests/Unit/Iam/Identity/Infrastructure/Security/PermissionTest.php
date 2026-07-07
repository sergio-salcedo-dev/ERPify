<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\InvalidPermission;
use Erpify\Iam\Identity\Infrastructure\Security\Permission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Permission::class)]
#[CoversClass(InvalidPermission::class)]
final class PermissionTest extends TestCase
{
    public function testItSplitsResourceAndActionFromTheCanonicalForm(): void
    {
        $permission = Permission::fromString('bank.write');

        $this->assertSame('bank', $permission->resource());
        $this->assertSame('write', $permission->action());
        $this->assertSame('bank.write', $permission->toString());
    }

    public function testTwoPermissionsWithTheSamePairAreEqual(): void
    {
        $this->assertTrue(Permission::fromString('bank.read')->equals(Permission::fromString('bank.read')));
    }

    public function testPermissionsDifferingInResourceOrActionAreNotEqual(): void
    {
        $bankRead = Permission::fromString('bank.read');

        $this->assertFalse($bankRead->equals(Permission::fromString('bank.write')));
        $this->assertFalse($bankRead->equals(Permission::fromString('account.read')));
    }

    #[DataProvider('provideItRecognisesAWellFormedPermissionCases')]
    public function testItRecognisesAWellFormedPermission(string $candidate): void
    {
        $this->assertTrue(Permission::isWellFormed($candidate));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideItRecognisesAWellFormedPermissionCases(): iterable
    {
        yield 'resource and action' => ['bank.write'];
        yield 'single-character parts' => ['a.b'];
        yield 'camelCase segments' => ['auditTrail.changeStatus'];
        yield 'digits after the first letter' => ['bank2.read3'];
    }

    #[DataProvider('provideMalformedPermissionCases')]
    public function testItRejectsAMalformedShape(string $candidate): void
    {
        $this->assertFalse(Permission::isWellFormed($candidate));
    }

    #[DataProvider('provideMalformedPermissionCases')]
    public function testFromStringThrowsOnAMalformedShape(string $candidate): void
    {
        $this->expectException(InvalidPermission::class);

        Permission::fromString($candidate);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedPermissionCases(): iterable
    {
        yield 'no separator' => ['bankwrite'];
        yield 'empty resource' => ['.write'];
        yield 'empty action' => ['bank.'];
        yield 'only the separator' => ['.'];
        yield 'more than one separator' => ['bank.account.write'];
        yield 'empty string' => [''];
        yield 'trailing separator' => ['bank.read.'];
        yield 'leading whitespace' => [' bank.write'];
        yield 'whitespace inside the action' => ['bank. write'];
        yield 'wildcard action collides with the tier sentinel' => ['bank.*'];
        yield 'hyphen is not part of the vocabulary' => ['bank-account.read'];
        yield 'segment starting with a digit' => ['1bank.read'];
    }
}
