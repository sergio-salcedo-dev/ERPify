<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\Permission;
use Erpify\Iam\Identity\Infrastructure\Security\StaticAuthorizationPolicy;
use Erpify\Shared\Access\Domain\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(StaticAuthorizationPolicy::class)]
final class StaticAuthorizationPolicyTest extends TestCase
{
    public function testAdminIsGrantedEveryActionIncludingOptedOutResources(): void
    {
        $policy = new StaticAuthorizationPolicy(tierOptOut: ['ledger']);
        $admin = [Role::ADMIN->value];

        $this->assertTrue($policy->permits(Permission::fromString('bank.read'), $admin));
        $this->assertTrue($policy->permits(Permission::fromString('bank.write'), $admin));
        $this->assertTrue($policy->permits(Permission::fromString('bank.delete'), $admin));
        $this->assertTrue($policy->permits(Permission::fromString('ledger.read'), $admin));
    }

    public function testViewerReadsButNeitherWritesNorDeletes(): void
    {
        $policy = new StaticAuthorizationPolicy();
        $viewer = [Role::VIEWER->value];

        $this->assertTrue($policy->permits(Permission::fromString('bank.read'), $viewer));
        $this->assertFalse($policy->permits(Permission::fromString('bank.write'), $viewer));
        $this->assertFalse($policy->permits(Permission::fromString('bank.delete'), $viewer));
    }

    public function testEditorWritesButDoesNotDelete(): void
    {
        $policy = new StaticAuthorizationPolicy();
        $editor = [Role::EDITOR->value];

        $this->assertTrue($policy->permits(Permission::fromString('bank.read'), $editor));
        $this->assertTrue($policy->permits(Permission::fromString('bank.write'), $editor));
        $this->assertFalse($policy->permits(Permission::fromString('bank.delete'), $editor));
    }

    public function testManagerDeletes(): void
    {
        $policy = new StaticAuthorizationPolicy();

        $this->assertTrue($policy->permits(Permission::fromString('bank.delete'), [Role::MANAGER->value]));
    }

    public function testASubjectWithoutAGrantingTierOrRuleIsDenied(): void
    {
        $policy = new StaticAuthorizationPolicy();

        // No roles at all, and a role that neither tiers nor appears in an explicit grant, are both denied.
        $this->assertFalse($policy->permits(Permission::fromString('bank.read'), []));
        $this->assertFalse($policy->permits(Permission::fromString('bank.read'), [Role::AUDIT_READER->value]));
    }

    public function testAnExplicitGrantAllowsExactlyTheListedRoles(): void
    {
        $policy = new StaticAuthorizationPolicy(
            tierVerbs: [],
            explicitGrants: ['report.export' => [Role::VIEWER->value]],
        );

        $this->assertTrue($policy->permits(Permission::fromString('report.export'), [Role::VIEWER->value]));
        $this->assertFalse($policy->permits(Permission::fromString('report.export'), [Role::EDITOR->value]));
    }

    public function testTierOptOutBlocksTheTierAutoGrantButNotAnExplicitGrant(): void
    {
        $policy = new StaticAuthorizationPolicy(
            explicitGrants: ['ledger.read' => [Role::AUDIT_READER->value]],
            tierOptOut: ['ledger'],
        );

        // MANAGER would earn ledger.read through its tier, but the resource opted out of tiering.
        $this->assertFalse($policy->permits(Permission::fromString('ledger.read'), [Role::MANAGER->value]));
        // A non-opted-out resource still tiers as usual.
        $this->assertTrue($policy->permits(Permission::fromString('bank.read'), [Role::MANAGER->value]));
        // The explicit grant still reaches the opted-out resource.
        $this->assertTrue($policy->permits(Permission::fromString('ledger.read'), [Role::AUDIT_READER->value]));
    }

    public function testAWildcardTierGrantsEveryAction(): void
    {
        // A tier whose verb list is the wildcard sentinel may perform any action. Exercised with a
        // non-ADMIN token so the wildcard branch runs on its own, not short-circuited by the ADMIN clause.
        $policy = new StaticAuthorizationPolicy(tierVerbs: ['SUPERVISOR' => ['*']]);
        $supervisor = ['SUPERVISOR'];

        $this->assertTrue($policy->permits(Permission::fromString('bank.read'), $supervisor));
        $this->assertTrue($policy->permits(Permission::fromString('bank.delete'), $supervisor));
        $this->assertTrue($policy->permits(Permission::fromString('ledger.approve'), $supervisor));
    }

    public function testAuditTrailReadIsGrantedOnlyToAuditReaderAndAdmin(): void
    {
        $policy = new StaticAuthorizationPolicy();
        $permission = Permission::fromString('auditTrail.read');

        $this->assertTrue($policy->permits($permission, [Role::AUDIT_READER->value]));
        // ADMIN reads the trail through the unconditional admin clause, not the explicit grant.
        $this->assertTrue($policy->permits($permission, [Role::ADMIN->value]));

        // auditTrail opts out of tier auto-grant, so a generic read-tier role earns nothing without an explicit grant.
        $this->assertFalse($policy->permits($permission, [Role::VIEWER->value]));
        $this->assertFalse($policy->permits($permission, [Role::EDITOR->value]));
        $this->assertFalse($policy->permits($permission, [Role::MANAGER->value]));
        $this->assertFalse($policy->permits($permission, []));

        // The refusal is opt-out-specific, not a blanket VIEWER denial: the same VIEWER still reads a tiered
        // resource — bankAccount — pinning that only auditTrail's opt-out, not a broken tier, withholds the
        // trail from it. This paired contrast also guards against a future regression adding bankAccount to
        // tierOptOut (which would silently strip its read auto-grant).
        $this->assertTrue($policy->permits(Permission::fromString('bankAccount.read'), [Role::VIEWER->value]));

        // Roles are additive: a principal holding a generic tier alongside AUDIT_READER is still granted
        // through the explicit clause — the union of roles decides, not any single one.
        $this->assertTrue($policy->permits($permission, [Role::VIEWER->value, Role::AUDIT_READER->value]));
    }

    public function testBankAccountChangeStatusIsGrantedOnlyToManagerAndAdmin(): void
    {
        $policy = new StaticAuthorizationPolicy();
        $permission = Permission::fromString('bankAccount.changeStatus');

        // changeStatus is a domain operation, not a tier verb, so only the explicit grant (MANAGER) and
        // the unconditional admin clause reach it.
        $this->assertTrue($policy->permits($permission, [Role::MANAGER->value]));
        $this->assertTrue($policy->permits($permission, [Role::ADMIN->value]));

        // An EDITOR holds the write tier, yet changeStatus is not a tier verb — write does not imply it.
        $this->assertFalse($policy->permits($permission, [Role::EDITOR->value]));
        $this->assertFalse($policy->permits($permission, [Role::VIEWER->value]));
        $this->assertFalse($policy->permits($permission, []));
    }
}
