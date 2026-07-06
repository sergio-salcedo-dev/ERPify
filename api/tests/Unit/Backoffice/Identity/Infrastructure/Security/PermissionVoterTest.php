<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Identity\Infrastructure\Security;

use Erpify\Backoffice\Identity\Infrastructure\Security\PermissionVoter;
use Erpify\Tests\Unit\Backoffice\Identity\Infrastructure\Security\Fixtures\RecordingAuthorizationPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * @internal
 */
#[CoversClass(PermissionVoter::class)]
final class PermissionVoterTest extends TestCase
{
    #[DataProvider('provideItAbstainsOnNonPermissionAttributesCases')]
    public function testItAbstainsOnNonPermissionAttributes(string $attribute): void
    {
        $policy = new RecordingAuthorizationPolicy(true);
        $voter = new PermissionVoter($policy);
        $token = $this->tokenWithRoles(['ROLE_AUDIT_READER']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, [$attribute]));
        // The stronger invariant: a non-permission attribute never reaches the policy at all.
        $this->assertSame([], $policy->calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideItAbstainsOnNonPermissionAttributesCases(): iterable
    {
        yield 'a role attribute' => ['ROLE_AUDIT_READER'];
        yield 'an authentication attribute' => ['IS_AUTHENTICATED_FULLY'];
        yield 'the public marker' => ['PUBLIC_ACCESS'];
    }

    public function testItGrantsOrDeniesPermissionShapedAttributesFollowingThePolicy(): void
    {
        $token = $this->tokenWithRoles(['ROLE_VIEWER']);

        $granting = new PermissionVoter(new RecordingAuthorizationPolicy(true));
        $denying = new PermissionVoter(new RecordingAuthorizationPolicy(false));

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $granting->vote($token, null, ['bank.read']));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $denying->vote($token, null, ['bank.write']));
    }

    public function testItStripsTheRolePrefixAndBuildsThePermissionBeforeDelegating(): void
    {
        $policy = new RecordingAuthorizationPolicy(true);
        $voter = new PermissionVoter($policy);
        $token = $this->tokenWithRoles(['ROLE_VIEWER', 'ROLE_AUDIT_READER']);

        $voter->vote($token, null, ['bank.read']);

        $this->assertSame([['VIEWER', 'AUDIT_READER']], \array_column($policy->calls, 'roles'));
        $this->assertSame(
            ['bank.read'],
            \array_map(static fn (array $call): string => $call['permission']->toString(), $policy->calls),
        );
    }

    public function testItIgnoresRoleTokensWithoutThePrefix(): void
    {
        $policy = new RecordingAuthorizationPolicy(true);
        $voter = new PermissionVoter($policy);
        // A bare 'ADMIN' (no ROLE_ prefix) must be dropped, never honoured as the ADMIN tier.
        $token = $this->tokenWithRoles(['ROLE_VIEWER', 'ADMIN']);

        $voter->vote($token, null, ['bank.read']);

        $this->assertSame([['VIEWER']], \array_column($policy->calls, 'roles'));
    }

    public function testItDoesNotReadTheSubject(): void
    {
        $policy = new RecordingAuthorizationPolicy(true);
        $voter = new PermissionVoter($policy);
        $token = $this->tokenWithRoles(['ROLE_VIEWER']);

        $withSubject = $voter->vote($token, new stdClass(), ['bank.read']);
        $withoutSubject = $voter->vote($token, null, ['bank.read']);

        // The decision and the roles handed to the policy are identical with or without a subject.
        $this->assertSame($withSubject, $withoutSubject);
        $this->assertSame([['VIEWER'], ['VIEWER']], \array_column($policy->calls, 'roles'));
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
