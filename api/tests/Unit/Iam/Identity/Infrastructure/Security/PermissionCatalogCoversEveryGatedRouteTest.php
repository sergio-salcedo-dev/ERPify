<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Backoffice\Bank\Infrastructure\Controller\BankRealtimeAuthorizeController;
use Erpify\Iam\Identity\Infrastructure\Security\Permission;
use Erpify\Iam\Identity\Infrastructure\Security\PermissionCatalog;
use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\ImperativePermissionChecks;
use Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security\Fixtures\SubclassGatedFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;

/**
 * The gate that makes a central catalog safe to have. Enumerating permissions costs the design its best
 * property — a brand-new resource used to be governed on tier verbs alone, with zero configuration — and
 * without this test the price would be paid in silence: a permission gated on a route but missing from the
 * catalog still authorizes correctly server-side, it merely vanishes from `/me`, so the client hides a
 * control the user is entitled to and nothing anywhere fails. That is the worst kind of bug: invisible,
 * and indistinguishable from a deliberate denial.
 *
 * So the omission is made loud. Every `#[IsGranted]` in the tree is read back and matched against the
 * catalog; forgetting an entry fails the build with the permission and the file that needs it. The property
 * is not restored — it is traded for one that can be enforced.
 *
 * The attribute is not the only way a route is gated. A controller may also ask the authorization checker
 * mid-method — the shape a conditional grant needs, where the permission depends on the payload rather than on
 * the route — and reflection cannot see those at all. {@see ImperativePermissionChecks} reads them out of the
 * source instead, and the two sets are held to the same rule, so the sweep's coverage does not depend on which
 * of the two spellings an author reached for.
 *
 * A superset, never an equality: the catalog also carries permissions whose use case has not landed yet
 * (`users.invite` and friends), mirroring the seam the policy documents.
 *
 * @internal
 */
#[CoversClass(PermissionCatalog::class)]
final class PermissionCatalogCoversEveryGatedRouteTest extends TestCase
{
    public function testEveryGatedPermissionIsInTheCatalog(): void
    {
        $catalog = new PermissionCatalog();
        $gated = $this->gatedPermissions();

        $this->assertNotEmpty($gated, 'No #[IsGranted] permissions were found — the sweep is not reaching src.');

        foreach ($gated as $permission => $declaredIn) {
            $this->assertTrue($catalog->contains($permission), \sprintf(
                'Permission "%s" gates %s but is absent from PermissionCatalog. Add it: `/me` cannot advertise '
                . 'a permission the catalog does not list, so the client would silently hide the control.',
                $permission,
                $declaredIn,
            ));
        }
    }

    public function testEveryImperativelyCheckedPermissionIsInTheCatalog(): void
    {
        $catalog = new PermissionCatalog();
        $checked = ImperativePermissionChecks::in($this->apiSources());

        $this->assertNotEmpty(
            $checked,
            'No imperative permission check was found. Either the sweep stopped reaching src — in which case '
            . 'this assertion is the only thing standing between a missing catalog entry and silence — or the '
            . 'last conditional gate was removed and this test has nothing left to guard.',
        );

        foreach ($checked as $permission => $declaredIn) {
            $this->assertTrue($catalog->contains($permission), \sprintf(
                'Permission "%s" is checked imperatively in %s but is absent from PermissionCatalog. The '
                . 'attribute sweep cannot see this call, so nothing else would have told you: add it, or `/me` '
                . 'will never advertise it and the client will hide a control the user holds.',
                $permission,
                $declaredIn,
            ));
        }
    }

    /**
     * Sanity anchor for the sweep itself: most controllers are gated on the class, the realtime authorize
     * endpoints on the method. Reading only class attributes would leave the assertion above vacuously true
     * over a smaller set, so the method path is pinned against a controller that has nothing at class level —
     * if the sweep still finds its permission, it can only have come from the method.
     */
    public function testTheSweepReadsAttributesDeclaredOnMethodsNotOnlyOnClasses(): void
    {
        $methodGated = new ReflectionClass(BankRealtimeAuthorizeController::class);

        $this->assertEmpty(
            $methodGated->getAttributes(IsGranted::class),
            'Precondition drifted: this controller is now gated at class level, '
            . 'so it no longer exercises the method path.',
        );
        $this->assertNotEmpty(
            $this->isGrantedAttributesOf($methodGated),
            'The sweep must read #[IsGranted] declared on methods, not just on classes.',
        );
    }

    /**
     * Every permission string reached by an `#[IsGranted]` anywhere under `api/src`, mapped to a class that
     * declares it (for the failure message). Attributes are read off both the class and its methods, since
     * the codebase gates both ways.
     *
     * @return array<string, string> permission => a declaring class short name
     */
    private function gatedPermissions(): array
    {
        $gated = [];

        foreach ($this->classesUnderApiSrc() as $reflectionClass) {
            $unclassifiable = [];

            foreach ($this->permissionsDeclaredOn($reflectionClass, $unclassifiable) as $permission) {
                $gated[$permission] = $reflectionClass->getShortName();
            }

            $this->assertSame([], $unclassifiable, \sprintf(
                '%s gates a route with an #[IsGranted] whose argument is not a plain string (an Expression or a '
                . 'Closure). The catalog sweep cannot tell which permission it governs, so `/me` could omit it '
                . 'without anything failing: gate on a "resource.action" string, or teach this sweep to classify it.',
                $reflectionClass->getName(),
            ));
        }

        return $gated;
    }

    /**
     * Permission strings gated on a class and its methods, `IsGranted` subclasses included. The attribute
     * lookup is by instance-of, not by exact name: `IsGranted` is not final, so a project-local subclass
     * would otherwise gate a route while the sweep saw nothing.
     *
     * @template T of object
     *
     * @param ReflectionClass<T> $reflectionClass
     * @param list<string>       $unclassifiable  receives a description of each non-string attribute argument
     *
     * @return list<string>
     */
    private function permissionsDeclaredOn(ReflectionClass $reflectionClass, array &$unclassifiable): array
    {
        $permissions = [];

        foreach ($this->isGrantedAttributesOf($reflectionClass) as $attribute) {
            $declared = $attribute->newInstance()->attribute;

            if (!\is_string($declared)) {
                $unclassifiable[] = \get_debug_type($declared);

                continue;
            }

            // Role and authenticated tokens (`ROLE_*`, `IS_AUTHENTICATED_*`, `PUBLIC_ACCESS`) carry no
            // `resource.action` separator and are not permissions this catalog governs.
            if (Permission::isWellFormed($declared)) {
                $permissions[] = $declared;
            }
        }

        return $permissions;
    }

    /**
     * `IsGranted` is repeatable, so every attribute is read, not just the first.
     *
     * @template T of object
     *
     * @param ReflectionClass<T> $reflectionClass
     *
     * @return list<ReflectionAttribute<IsGranted>>
     */
    private function isGrantedAttributesOf(ReflectionClass $reflectionClass): array
    {
        $attributes = $reflectionClass->getAttributes(IsGranted::class, ReflectionAttribute::IS_INSTANCEOF);

        foreach ($reflectionClass->getMethods() as $reflectionMethod) {
            $attributes = [
                ...$attributes,
                ...$reflectionMethod->getAttributes(IsGranted::class, ReflectionAttribute::IS_INSTANCEOF),
            ];
        }

        return $attributes;
    }

    /**
     * `security.yaml` `access_control` is a second gating surface over `/api`. Today every rule there is
     * `PUBLIC_ACCESS` or the `IS_AUTHENTICATED_FULLY` catch-all; a rule that gated a path on a permission
     * (or on an `allow_if` expression) would bypass the attribute sweep entirely.
     */
    public function testAccessControlRulesDoNotGateApiPathsOutsideTheCatalog(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 6) . '/config/packages/security.yaml', Yaml::PARSE_CONSTANT);
        $this->assertIsArray($config);
        $security = $config['security'] ?? null;
        $this->assertIsArray($security);
        $rules = $security['access_control'] ?? null;

        $this->assertIsArray($rules, 'security.yaml no longer declares access_control: update this sweep.');
        $this->assertNotEmpty($rules);
        $rules = \array_values(\array_filter($rules, 'is_array'));
        $this->assertSame([], $this->accessControlProblems($rules, new PermissionCatalog()));
    }

    public function testTheSweepSeesIsGrantedSubclasses(): void
    {
        $unclassifiable = [];

        $this->assertSame(
            ['widgets.read'],
            $this->permissionsDeclaredOn(new ReflectionClass(SubclassGatedFixture::class), $unclassifiable),
            'An IsGranted subclass must be swept: an exact-name lookup would report nothing here.',
        );
        $this->assertSame([], $unclassifiable);
    }

    public function testTheAccessControlEngineRejectsUnclassifiedRules(): void
    {
        $catalog = new PermissionCatalog();

        $this->assertSame([], $this->accessControlProblems([
            ['path' => '^/api/v1/health$', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/api', 'roles' => 'IS_AUTHENTICATED_FULLY'],
        ], $catalog));
        $this->assertCount(1, $this->accessControlProblems([
            ['path' => '^/api/v1/x$', 'roles' => 'widgets.read'],
        ], $catalog), 'A permission absent from the catalog must be reported.');
        $this->assertCount(1, $this->accessControlProblems([
            ['path' => '^/api/v1/x$', 'roles' => 'IS_AUTHENTICATED_FULLY', 'allow_if' => 'true'],
        ], $catalog), 'An allow_if expression must fail closed.');
        $this->assertCount(1, $this->accessControlProblems([
            ['path' => '^/api/v1/x$', 'roles' => ['ROLE_ADMIN']],
        ], $catalog), 'A role gate on an API path is not a catalog permission and must be classified.');
        $this->assertSame([], $this->accessControlProblems([
            ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
        ], $catalog), 'Rules outside /api are out of scope.');
    }

    /**
     * @param list<array<mixed>> $rules
     *
     * @return list<string>
     */
    private function accessControlProblems(array $rules, PermissionCatalog $catalog): array
    {
        $problems = [];

        foreach ($rules as $rule) {
            $rawPath = $rule['path'] ?? '';
            $path = \is_string($rawPath) ? $rawPath : '';

            if (!\str_contains($path, '/api')) {
                continue;
            }

            if (isset($rule['allow_if'])) {
                $problems[] = \sprintf('access_control "%s" uses allow_if, which this sweep cannot classify.', $path);
            }

            foreach ((array) ($rule['roles'] ?? []) as $role) {
                if (!\is_string($role)) {
                    $problems[] = \sprintf('access_control "%s" declares a non-string role.', $path);

                    continue;
                }

                if ('PUBLIC_ACCESS' === $role || \str_starts_with($role, 'IS_AUTHENTICATED_')) {
                    continue;
                }

                if (Permission::isWellFormed($role) && $catalog->contains($role)) {
                    continue;
                }

                $problems[] = \sprintf(
                    'access_control "%s" gates on "%s", which is neither a public/authenticated token nor a catalog '
                    . 'permission: add it to PermissionCatalog or classify it here.',
                    $path,
                    $role,
                );
            }
        }

        return $problems;
    }

    /**
     * The conditional grant the imperative sweep exists for, pinned by name. Without it the assertion above
     * would survive a regex that matched nothing in particular — `assertNotEmpty` only proves the sweep found
     * *something*, not that it found the call shape actually in the tree, which passes its permission as a
     * class constant rather than as a literal.
     */
    public function testTheImperativeSweepResolvesAPermissionPassedAsAClassConstant(): void
    {
        $this->assertContains(
            'users.grantAdmin',
            \array_keys(ImperativePermissionChecks::in($this->apiSources())),
            'The delegation gate is checked as `self::GRANT_ADMIN_PERMISSION`; a sweep that only understood '
            . 'quoted literals would report nothing here and stay green over an empty set.',
        );
    }

    /**
     * @return array<string, string> path relative to `api/src` => file contents
     */
    private function apiSources(): array
    {
        $srcRoot = ApiSourceFiles::root();
        $sources = [];

        foreach (ApiSourceFiles::phpFiles($srcRoot) as $file) {
            $sources[\substr($file->getPathname(), \strlen($srcRoot) + 1)] = (string) \file_get_contents(
                $file->getPathname(),
            );
        }

        return $sources;
    }

    /**
     * Autoloadable classes under `api/src`, derived from the shared {@see ApiSourceFiles} walk plus the PSR-4
     * `Erpify\` → `src/` mapping — the same layering the error-contract marker gate uses.
     *
     * @return iterable<ReflectionClass<object>>
     */
    private function classesUnderApiSrc(): iterable
    {
        $srcRoot = ApiSourceFiles::root();

        foreach (ApiSourceFiles::phpFiles($srcRoot) as $file) {
            $relative = \substr($file->getPathname(), \strlen($srcRoot) + 1, -\strlen('.php'));
            $fqcn = 'Erpify\\' . \str_replace('/', '\\', $relative);

            if (!\class_exists($fqcn)) {
                continue;
            }

            yield new ReflectionClass($fqcn);
        }
    }
}
