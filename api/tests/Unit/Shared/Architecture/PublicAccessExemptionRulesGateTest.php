<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\PublicAccessExemptionRegistry;
use Erpify\Tests\Support\PublicAccessExemptionRules;
use Erpify\Tests\Support\PublicAccessPrefixBoundary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Falsifiability of the rules the sibling gate applies. Those assertions run against a tree that is
 * correct, so they would read identically if the rules could not detect anything — this drives each rule
 * over input that violates it, without a dirty entry ever existing in the real files.
 *
 * @internal
 */
#[CoversClass(PublicAccessExemptionRegistry::class)]
#[CoversClass(PublicAccessExemptionRules::class)]
#[CoversClass(PublicAccessPrefixBoundary::class)]
final class PublicAccessExemptionRulesGateTest extends TestCase
{
    /**
     * Four shapes admit an anonymous caller and only the first says so. `route:` is an alternative matcher
     * Symfony accepts; absent or empty roles are stronger still, because `AccessListener` returns before
     * deciding anything at all. A sweep keyed on the PUBLIC_ACCESS token sees none of the last three, and
     * the token also appears in prose — so the universe is parsed, and parsed from the rule's effect.
     */
    public function testTheUniverseCoversEveryShapeThatAdmitsAnAnonymousCaller(): void
    {
        $security = Yaml::parse(<<<'YAML'
            security:
                access_control:
                    # The login handshake is a PUBLIC_ACCESS route by necessity.
                    - { path: '^/api/v1/login$', roles: PUBLIC_ACCESS }
                    - { path: '^/api/v1/health$', roles: [PUBLIC_ACCESS] }
                    # - { path: '^/api/v1/retired$', roles: PUBLIC_ACCESS }
                    - { route: backoffice_health_database, roles: PUBLIC_ACCESS }
                    - { path: '^/api/v1/empty$', roles: [] }
                    - { path: '^/api/v1/absent$' }
                    - { path: '^/api', roles: IS_AUTHENTICATED_FULLY }
            YAML);

        $this->assertIsArray($security);
        $this->assertSame(
            [
                '^/api/v1/login$',
                '^/api/v1/health$',
                'route:backoffice_health_database',
                '^/api/v1/empty$',
                '^/api/v1/absent$',
            ],
            PublicAccessExemptionRules::exemptionsIn($security),
        );
    }

    public function testARuleWhoseAdmissionThisGateCannotEvaluateIsRefused(): void
    {
        $security = Yaml::parse("security:\n    access_control:\n        - { path: '^/x$', allow_if: \"true\" }\n");
        $this->assertIsArray($security);
        $this->expectException(RuntimeException::class);

        PublicAccessExemptionRules::exemptionsIn($security);
    }

    /**
     * First match wins, so a catch-all that is absent — or merely no longer last — leaves every unlisted
     * route open while the bijection stays perfect.
     */
    public function testTheCatchAllMustExistAndBeTheLastRule(): void
    {
        $catchAll = "        - { path: '^/api', roles: IS_AUTHENTICATED_FULLY }\n";
        $closed = Yaml::parse("security:\n    access_control:\n" . $catchAll);
        $reordered = Yaml::parse(
            "security:\n    access_control:\n" . $catchAll
            . "        - { path: '^/api/v1/late$', roles: PUBLIC_ACCESS }\n",
        );
        $this->assertIsArray($closed);
        $this->assertIsArray($reordered);

        $this->assertSame([], PublicAccessExemptionRules::defaultDenyViolations($closed));
        $this->assertCount(1, PublicAccessExemptionRules::defaultDenyViolations($reordered));
        $this->assertCount(1, PublicAccessExemptionRules::defaultDenyViolations([]));
    }

    #[DataProvider('provideAnUnreadableClassificationIsRefusedRatherThanSkippedCases')]
    public function testAnUnreadableClassificationIsRefusedRatherThanSkipped(string $line): void
    {
        $this->expectException(RuntimeException::class);

        PublicAccessExemptionRegistry::parse([$line]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnUnreadableClassificationIsRefusedRatherThanSkippedCases(): iterable
    {
        yield 'no verdict at all' => ['^/api/v1/health$ :: the smoke test'];
        yield 'no consumer named' => ['^/api/v1/health$ => exact'];
        yield 'an empty consumer' => ['^/api/v1/health$ => exact ::'];
        yield 'unknown match form' => ['^/api/v1/health$ => anchored :: the smoke test'];
        yield 'a prefix naming no bounding file' => ['^/api/test/ => prefix :: the functional suite'];
        yield 'an exact naming one' => ['^/api/v1/health$ => exact :: the smoke test :: config/routes/test.yaml'];
        yield 'a surplus field' => ['^/api/test/ => prefix :: suite :: config/routes/test.yaml :: bogus'];
    }

    public function testTheBijectionIsCheckedInBothDirections(): void
    {
        $registry = PublicAccessExemptionRegistry::parse(['^/api/v1/health$ => exact :: the smoke test']);

        $unclassified = PublicAccessExemptionRules::bijectionViolations(
            ['^/api/v1/health$', '^/api/v1/backdoor$'],
            $registry,
        );
        $orphaned = PublicAccessExemptionRules::bijectionViolations([], $registry);

        $this->assertCount(1, $unclassified);
        $this->assertStringContainsString('^/api/v1/backdoor$', $unclassified[0]);
        $this->assertCount(1, $orphaned);
        $this->assertStringContainsString('no longer admits', $orphaned[0]);
    }

    /**
     * The trailing `$` alone does not establish "one route": an alternation and a wildcard are both
     * anchored and both span a set — the second reopens exactly the subtree whose loss of anchoring made
     * the database probe anonymous. So an `exact` pattern must be a literal path.
     */
    public function testAnExactClassificationMustBeALiteralSingleRoute(): void
    {
        $violations = PublicAccessExemptionRules::formViolations(PublicAccessExemptionRegistry::parse([
            '^/api/v1/health => exact :: the smoke test',
            '^/api/v1/(health|backoffice/health/database)$ => exact :: the smoke test',
            '^/api/v1/backoffice/health.*$ => exact :: the smoke test',
            '^/api/test/$ => prefix :: the functional suite :: config/routes/test.yaml',
        ]));

        $this->assertCount(4, $violations);
        $this->assertStringContainsString('is not anchored', $violations[0]);
        $this->assertStringContainsString('is not a literal path', $violations[1]);
        $this->assertStringContainsString('is not a literal path', $violations[2]);
        $this->assertStringContainsString('matches a single target', $violations[3]);
    }

    public function testAPrefixRouteIsUnboundedUnlessItSitsInsideTheBlockOfTheFileNamed(): void
    {
        $bounded = Yaml::parse("when@test:\n    a_route:\n        path: /api/test/_throw\n");
        $escaped = Yaml::parse("a_route:\n    path: /api/test/_throw\n");
        $this->assertIsArray($bounded);
        $this->assertIsArray($escaped);

        $this->assertSame([], PublicAccessPrefixBoundary::unboundedRoutes(
            '^/api/test/',
            'config/routes/test.yaml',
            ['config/routes/test.yaml' => $bounded],
        ));

        $inTheNamedFile = PublicAccessPrefixBoundary::unboundedRoutes(
            '^/api/test/',
            'config/routes/test.yaml',
            ['config/routes/test.yaml' => $escaped],
        );
        $inAnotherFile = PublicAccessPrefixBoundary::unboundedRoutes(
            '^/api/test/',
            'config/routes/test.yaml',
            ['config/routes/elsewhere.yaml' => $bounded],
        );

        $this->assertCount(1, $inTheNamedFile);
        $this->assertStringContainsString('outside any when@test block', $inTheNamedFile[0]);
        $this->assertCount(1, $inAnotherFile);
        $this->assertStringContainsString('config/routes/elsewhere.yaml', $inAnotherFile[0]);
    }

    /**
     * The vector a directory sweep cannot see: attribute routing declares no path here, so a resource whose
     * prefix does not exclude the exempt subtree can register one into production — and a resource nested
     * under `when@prod:` loads there exactly like a top-level one.
     */
    public function testAnAttributeResourceWhosePrefixCannotExcludeTheSubtreeIsUnbounded(): void
    {
        $topLevel = Yaml::parse("controllers:\n    resource: ../src/\n    type: attribute\n");
        $nested = Yaml::parse("when@prod:\n    controllers:\n        resource: ../src/\n        type: attribute\n");
        $excluded = Yaml::parse("controllers:\n    resource: ../src/\n    type: attribute\n    prefix: /api/v1\n");
        $this->assertIsArray($topLevel);
        $this->assertIsArray($nested);
        $this->assertIsArray($excluded);

        $this->assertCount(1, PublicAccessPrefixBoundary::unboundedRoutes(
            '^/api/test/',
            'config/routes/test.yaml',
            ['config/routes.yaml' => $topLevel],
        ));
        $this->assertCount(1, PublicAccessPrefixBoundary::unboundedRoutes(
            '^/api/test/',
            'config/routes/test.yaml',
            ['config/routes.yaml' => $nested],
        ));
        $this->assertSame([], PublicAccessPrefixBoundary::unboundedRoutes(
            '^/api/test/',
            'config/routes/test.yaml',
            ['config/routes.yaml' => $excluded],
        ));
    }

    /**
     * Symfony imports PHP routing alongside YAML. It cannot be read as data, so a mention of the exempt
     * prefix is reported rather than ignored — ignoring the format is how a route live in production sat
     * under an anonymous prefix with this gate green.
     */
    public function testAPhpRoutingFileMentioningTheExemptPrefixIsUnbounded(): void
    {
        $leaking = ['config/routes/leak.php' => "<?php \$routes->add('leak', '/api/test/pwn');"];
        $unrelated = ['config/routes/other.php' => "<?php \$routes->add('ok', '/api/v1/banks');"];

        $violations = PublicAccessPrefixBoundary::phpRoutingMentions('^/api/test/', $leaking);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('config/routes/leak.php', $violations[0]);
        $this->assertSame([], PublicAccessPrefixBoundary::phpRoutingMentions('^/api/test/', $unrelated));
    }
}
