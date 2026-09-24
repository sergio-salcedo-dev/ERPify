<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\CredentialProofPolicy;
use Erpify\Tests\Support\CredentialProofRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The gate over the real tree: every route the router declares is classified in
 * `api/.credential-proof-policy`, no line outlives its route, an `anonymous` route is one the firewall
 * actually exempts, and every `credential-affecting` route's controller spends the shared
 * `CurrentPasswordProofThrottle` budget in the action bound to it, verifies the submitted password, and only
 * then invokes a use-case method that calls `ProveCurrentPassword::ensure()`.
 *
 * The failure it exists for is a fourth credential route arriving with every other gate green.
 *
 * @internal
 */
#[CoversClass(CredentialProofPolicy::class)]
#[CoversClass(CredentialProofRules::class)]
final class CredentialProofGateTest extends TestCase
{
    /**
     * Every assertion below is satisfied by an empty universe, so a reader that stopped resolving the
     * manifest or the attributes would turn them vacuously green.
     */
    public function testTheSweepReachesTheRouteInventoryAndTheControllers(): void
    {
        $policy = $this->policy();

        $this->assertGreaterThanOrEqual(
            30,
            \count($policy->routePaths()),
            'The route manifest resolved almost nothing.',
        );
        $this->assertArrayHasKey(
            'iam_me_change_password',
            $policy->actionsByRoute(),
            'The #[Route] reflection no longer reaches the Identity controllers.',
        );
    }

    /**
     * The three acts that motivated the rule stay under it: reclassifying one as `ordinary` would pass every
     * other assertion here, which is the direction the registry cannot judge on its own.
     */
    public function testTheKnownCredentialActsStayClassifiedAsCredentialAffecting(): void
    {
        $registry = $this->policy()->registry();

        foreach (['iam_me_change_password', 'iam_me_mint_recovery_secret', 'iam_me_revoke_recovery_secret'] as $route) {
            $this->assertSame(CredentialProofRules::CREDENTIAL_AFFECTING, $registry[$route]['class'] ?? null, $route);
        }
    }

    public function testEveryRouteIsClassifiedAndEveryClassificationStillNamesARoute(): void
    {
        $policy = $this->policy();

        $this->assertSame(
            [],
            CredentialProofRules::bijectionViolations(\array_keys($policy->routePaths()), $policy->registry()),
        );
    }

    public function testEveryAnonymousRouteIsOneTheFirewallExempts(): void
    {
        $policy = $this->policy();

        $this->assertNotEmpty($policy->publicPatterns(), 'api/.public-access-exemptions resolved nothing.');
        $this->assertSame([], CredentialProofRules::anonymousViolations(
            $policy->registry(),
            $policy->routePaths(),
            $policy->publicPatterns(),
        ));
    }

    public function testEveryCredentialAffectingRouteSpendsTheBudgetAndProvesTheCurrentPassword(): void
    {
        $policy = $this->policy();
        $actions = $policy->actionsByRoute();
        $violations = [];

        foreach ($policy->registry() as $route => $entry) {
            if (CredentialProofRules::CREDENTIAL_AFFECTING !== $entry['class']) {
                continue;
            }

            $targets = $actions[$route] ?? [];
            $unresolved = CredentialProofRules::actionViolations($route, $targets);
            $target = $targets[0] ?? null;

            if ([] !== $unresolved || null === $target) {
                $violations = [...$violations, ...$unresolved];

                continue;
            }

            $violations = [
                ...$violations,
                ...CredentialProofRules::proofViolations(
                    $route,
                    CredentialProofPolicy::sourceOf($target['class']),
                    $target['method'],
                    CredentialProofPolicy::collaborators($target['class']),
                    CredentialProofPolicy::useCasesOf($target['class']),
                ),
            ];
        }

        $this->assertSame([], $violations);
    }

    private function policy(): CredentialProofPolicy
    {
        return CredentialProofPolicy::fromApiRoot(\dirname(__DIR__, 3));
    }
}
