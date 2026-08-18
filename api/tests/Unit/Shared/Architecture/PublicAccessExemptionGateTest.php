<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\PublicAccessExemptionRegistry;
use Erpify\Tests\Support\PublicAccessExemptionRules;
use Erpify\Tests\Support\PublicAccessExemptions;
use Erpify\Tests\Support\PublicAccessPrefixBoundary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The gate over the real tree: every rule admitting an unauthenticated caller is classified, every
 * classification still describes a live one, each one's form matches what it actually matches, a prefix
 * exemption is bounded by the file it names, and the allowlist is still closed by a default-deny catch-all.
 *
 * The firewall is the whole authorization story for a caller with no session, and adding an exemption was a
 * one-line diff no gate read. This is what makes that line cost a decision.
 *
 * @internal
 */
#[CoversClass(PublicAccessExemptions::class)]
#[CoversClass(PublicAccessExemptionRegistry::class)]
#[CoversClass(PublicAccessExemptionRules::class)]
#[CoversClass(PublicAccessPrefixBoundary::class)]
final class PublicAccessExemptionGateTest extends TestCase
{
    /**
     * Sanity anchor for the sweep itself: every assertion below is satisfied by an empty universe, so a
     * reader that stopped resolving `security.yaml` would turn all of them vacuously green.
     */
    public function testTheSweepReachesTheRealFirewallConfiguration(): void
    {
        $exemptions = $this->exemptions()->declaredExemptions();

        $this->assertNotEmpty(
            $exemptions,
            'No exemption was resolved — the sweep is not reaching security.yaml.',
        );
        $this->assertContains(
            '^/api/v1/backoffice/login$',
            $exemptions,
            'The login handshake must stay exempt; its absence means the resolution reads the wrong shape.',
        );
    }

    /**
     * The same anchor for the other reader. Without it, moving or renaming `config/routes/` disables the
     * prefix half in silence — measured: the assertion below still passes over zero routing sources.
     */
    public function testTheRouteSweepReachesEveryRoutingSource(): void
    {
        $exemptions = $this->exemptions();
        $configs = $exemptions->routeConfigs();

        $this->assertArrayHasKey('config/routes.yaml', $configs);
        $this->assertArrayHasKey('config/routes/test.yaml', $configs);
        $this->assertGreaterThanOrEqual(
            3,
            \count($configs) + \count($exemptions->phpRouteSources()),
            'The routing sweep resolved almost nothing — it is no longer reading the routes tree.',
        );
    }

    public function testEveryExemptionIsClassifiedAndEveryClassificationStillDescribesOne(): void
    {
        $exemptions = $this->exemptions();

        $this->assertSame([], PublicAccessExemptionRules::bijectionViolations(
            $exemptions->declaredExemptions(),
            $exemptions->registry(),
        ));
    }

    public function testEveryClassificationAgreesWithWhatItsPatternMatches(): void
    {
        $this->assertSame([], PublicAccessExemptionRules::formViolations($this->exemptions()->registry()));
    }

    /**
     * The half that makes a prefix classification falsifiable. Without it, "inert in production" survives
     * only as long as an unrelated file keeps a wrapper nobody watches.
     */
    public function testEveryPrefixExemptionIsBoundedByTheFileItNames(): void
    {
        $exemptions = $this->exemptions();
        $routeConfigs = $exemptions->routeConfigs();
        $phpSources = $exemptions->phpRouteSources();
        $violations = [];

        foreach ($exemptions->registry() as $pattern => $entry) {
            if (PublicAccessExemptionRegistry::PREFIX !== $entry['form']) {
                continue;
            }

            $violations = [
                ...$violations,
                ...PublicAccessPrefixBoundary::unboundedRoutes($pattern, $entry['boundedBy'] ?? '', $routeConfigs),
                ...PublicAccessPrefixBoundary::phpRoutingMentions($pattern, $phpSources),
            ];
        }

        $this->assertSame([], $violations);
    }

    /**
     * The allowlist only means anything while something denies the rest. Deleting the terminal rule — or
     * merely letting another one land after it, since first match wins — leaves every unlisted route open
     * with the bijection above still perfect.
     */
    public function testTheAllowlistIsClosedByADefaultDenyCatchAll(): void
    {
        $this->assertSame([], PublicAccessExemptionRules::defaultDenyViolations($this->exemptions()->securityConfig()));
    }

    private function exemptions(): PublicAccessExemptions
    {
        return PublicAccessExemptions::fromGateLocation(__DIR__);
    }
}
