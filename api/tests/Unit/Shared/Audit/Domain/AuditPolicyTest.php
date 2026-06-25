<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Domain;

use Erpify\Shared\Audit\Domain\AuditDecision;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditPolicy;
use Erpify\Shared\Audit\Domain\HttpInteraction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuditPolicy::class)]
#[CoversClass(AuditDecision::class)]
#[CoversClass(HttpInteraction::class)]
final class AuditPolicyTest extends TestCase
{
    private AuditPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AuditPolicy();
    }

    #[DataProvider('provideAuditsBackofficeReadsAsActivityWithAPrefixedRouteActionCases')]
    public function testAuditsBackofficeReadsAsActivityWithAPrefixedRouteAction(
        string $routeName,
        string $expectedAction,
    ): void {
        $decision = $this->policy->decide(new HttpInteraction($routeName, 'GET', false));

        $this->assertTrue($decision->isAuditable());
        $this->assertSame(AuditLevel::ACTIVITY, $decision->level);
        $this->assertSame($expectedAction, $decision->action);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideAuditsBackofficeReadsAsActivityWithAPrefixedRouteActionCases(): iterable
    {
        yield 'bank list/search' => ['backoffice_bank_search', 'ROUTE_BACKOFFICE_BANK_SEARCH'];
        yield 'bank detail' => ['backoffice_bank_get', 'ROUTE_BACKOFFICE_BANK_GET'];
    }

    public function testYieldsToCanonicalExplicitInstrumentation(): void
    {
        // The route declares its own canonical audit (it is recorded explicitly as BANK_ACCOUNTS_VIEWED,
        // richer: it carries the resource id). The generic path must stay silent so the interaction is
        // not recorded twice — the policy reads that declaration, it does not know the route by name.
        $decision = $this->policy->decide(new HttpInteraction('backoffice_bank_account_search', 'GET', true));

        $this->assertFalse($decision->isAuditable());
        $this->assertNotInstanceOf(AuditLevel::class, $decision->level);
        $this->assertNull($decision->action);
    }

    #[DataProvider('provideDoesNotAuditTechnicalOrNonBusinessInteractionsCases')]
    public function testDoesNotAuditTechnicalOrNonBusinessInteractions(string $routeName, string $method): void
    {
        $decision = $this->policy->decide(new HttpInteraction($routeName, $method, false));

        $this->assertFalse($decision->isAuditable());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideDoesNotAuditTechnicalOrNonBusinessInteractionsCases(): iterable
    {
        yield 'backoffice health' => ['backoffice_health', 'GET'];
        yield 'backoffice database health' => ['backoffice_health_database', 'GET'];
        yield 'frontoffice health' => ['frontoffice_health', 'GET'];
        yield 'mercure bootstrap' => ['frontoffice_mercure_bootstrap', 'GET'];
        yield 'mercure publish demo' => ['frontoffice_mercure_publish_demo', 'POST'];
        yield 'bank realtime authorize' => ['backoffice_bank_realtime_authorize', 'GET'];
        yield 'dev hot reload' => ['frontoffice_dev_frankenphp_hot_reload', 'GET'];
        yield 'media asset' => ['shared_media_get', 'GET'];
        yield 'stored object' => ['shared_stored_object_get', 'GET'];
        yield 'bank count helper' => ['backoffice_bank_count', 'GET'];
    }

    #[DataProvider('provideDoesNotAuditWritesViaTheGenericPathCases')]
    public function testDoesNotAuditWritesViaTheGenericPath(string $routeName, string $method): void
    {
        // State changes are the DomainEvent axis; the operational-activity hook captures reads only.
        $decision = $this->policy->decide(new HttpInteraction($routeName, $method, false));

        $this->assertFalse($decision->isAuditable());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideDoesNotAuditWritesViaTheGenericPathCases(): iterable
    {
        yield 'bank create' => ['backoffice_bank_create', 'POST'];
        yield 'bank update' => ['backoffice_bank_update', 'PUT'];
        yield 'bank delete' => ['backoffice_bank_delete', 'DELETE'];
    }

    public function testDoesNotAuditUnroutableRequests(): void
    {
        $decision = $this->policy->decide(new HttpInteraction(null, 'GET', false));

        $this->assertFalse($decision->isAuditable());
        $this->assertNotInstanceOf(AuditLevel::class, $decision->level);
        $this->assertNull($decision->action);
    }
}
