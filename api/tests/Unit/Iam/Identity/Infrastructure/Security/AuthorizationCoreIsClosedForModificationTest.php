<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\AuthorizationPolicy;
use Erpify\Iam\Identity\Infrastructure\Security\Permission;
use Erpify\Iam\Identity\Infrastructure\Security\PermissionVoter;
use Erpify\Iam\Identity\Infrastructure\Security\StaticAuthorizationPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * The expensive half of the OCP tripwire: adding a resource must never modify the authorization core
 * (voter + `Permission` value + `AuthorizationPolicy` port). The cheap half — the policy holds only data,
 * never mechanism — is `StaticAuthorizationPolicyIsDataOnlyTest`; this test does not repeat it.
 *
 * There is no way to assert "the developer did not edit the voter", so this asserts the *consequence*: a
 * resource the production policy has never heard of is governed end-to-end by the unmodified core, on tier
 * verbs alone, with zero policy rows. If governing a new resource required touching the voter, the value or
 * the port, this test could not be written against the untouched production core — so the test itself is the
 * proof that a new CRUD-only resource costs zero core edits and zero policy rows (the OCP goal at full
 * strength). It drives through the real {@see PermissionVoter} so the whole core-set is exercised, not the
 * policy alone. A second check freezes the port contract by reflection, because the drive-through proves the
 * core *works* for a new resource but not that the port's *shape* stays the minimal closed contract — a
 * second port method (e.g. a subject-scoped `permits`) is exactly the ABAC drift D9 forbids.
 *
 * @internal
 */
#[CoversClass(PermissionVoter::class)]
final class AuthorizationCoreIsClosedForModificationTest extends TestCase
{
    /**
     * A resource the production policy does not mention — the canonical "CRUD-only future resource" the ADR
     * uses (Invoice). It must stay absent from every policy map for this test to keep testing an *unknown*
     * resource; {@see self::testTheTestResourceStaysUnknownToThePolicy} pins that.
     */
    private const string UNKNOWN_RESOURCE = 'invoice';

    private const int GRANTED = VoterInterface::ACCESS_GRANTED;

    private const int DENIED = VoterInterface::ACCESS_DENIED;

    #[DataProvider('provideANewResourceIsGovernedByTierVerbsAloneCases')]
    public function testANewResourceIsGovernedByTierVerbsAlone(
        string $role,
        string $permission,
        int $expectedVote,
    ): void {
        $voter = new PermissionVoter(new StaticAuthorizationPolicy());

        $this->assertSame($expectedVote, $voter->vote($this->tokenWithRoles([$role]), null, [$permission]));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function provideANewResourceIsGovernedByTierVerbsAloneCases(): iterable
    {
        yield 'viewer reads' => ['ROLE_VIEWER', 'invoice.read', self::GRANTED];
        yield 'viewer does not write' => ['ROLE_VIEWER', 'invoice.write', self::DENIED];
        yield 'viewer does not delete' => ['ROLE_VIEWER', 'invoice.delete', self::DENIED];
        yield 'editor writes' => ['ROLE_EDITOR', 'invoice.write', self::GRANTED];
        yield 'editor does not delete' => ['ROLE_EDITOR', 'invoice.delete', self::DENIED];
        yield 'manager deletes' => ['ROLE_MANAGER', 'invoice.delete', self::GRANTED];
        yield 'admin reads' => ['ROLE_ADMIN', 'invoice.read', self::GRANTED];
        // The ADMIN wildcard reaches an action no tier verb and no explicit grant covers.
        yield 'admin performs an unlisted domain op' => ['ROLE_ADMIN', 'invoice.approve', self::GRANTED];
        // A domain op is not a tier verb, so without an explicit grant no non-admin tier reaches it: the new
        // resource needs zero policy rows for its CRUD and stays closed beyond them.
        yield 'manager does not perform an unlisted domain op' => ['ROLE_MANAGER', 'invoice.approve', self::DENIED];
    }

    public function testTheAuthorizationPolicyPortContractIsClosed(): void
    {
        $port = new ReflectionClass(AuthorizationPolicy::class);

        $this->assertCount(
            1,
            $port->getMethods(),
            'The AuthorizationPolicy port must expose exactly one method; a second (e.g. a subject-scoped '
            . 'permits) would put row-level scope into the contract — the ABAC drift D9 forbids.',
        );
        $this->assertTrue($port->hasMethod('permits'));

        $permits = $port->getMethod('permits');
        $parameterTypes = \array_map(
            fn (ReflectionParameter $parameter): ?string => $this->typeName($parameter->getType()),
            $permits->getParameters(),
        );

        $this->assertSame([Permission::class, 'array'], $parameterTypes);
        $this->assertSame('bool', $this->typeName($permits->getReturnType()));
    }

    public function testTheTestResourceStaysUnknownToThePolicy(): void
    {
        $file = (new ReflectionClass(StaticAuthorizationPolicy::class))->getFileName();
        $this->assertIsString($file);

        $source = \file_get_contents($file);
        $this->assertIsString($source);

        $this->assertStringNotContainsString(self::UNKNOWN_RESOURCE, $source, \sprintf(
            'This gate assumes "%s" is a resource the policy never lists; it now appears in the policy source. '
            . 'Pick another absent resource for the OCP drive-through.',
            self::UNKNOWN_RESOURCE,
        ));
    }

    private function typeName(mixed $type): ?string
    {
        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }

    /**
     * @param list<string> $roles
     */
    private function tokenWithRoles(array $roles): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getRoleNames')->willReturn($roles);

        return $token;
    }
}
