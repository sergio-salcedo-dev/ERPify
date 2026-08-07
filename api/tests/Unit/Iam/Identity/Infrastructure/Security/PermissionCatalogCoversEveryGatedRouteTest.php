<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Backoffice\Bank\Infrastructure\Controller\BankRealtimeAuthorizeController;
use Erpify\Iam\Identity\Infrastructure\Security\Permission;
use Erpify\Iam\Identity\Infrastructure\Security\PermissionCatalog;
use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\ImperativePermissionChecks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
            foreach ($this->isGrantedAttributesOf($reflectionClass) as $attribute) {
                $permission = $this->permissionOf($attribute);

                if (null === $permission) {
                    continue;
                }

                $gated[$permission] = $reflectionClass->getShortName();
            }
        }

        return $gated;
    }

    /**
     * `IsGranted::$attribute` is `string|Expression|Closure`, and the argument may be passed positionally or
     * by name — so the attribute is instantiated and its property read, rather than picking `getArguments()[0]`
     * (absent under a named argument, an object under an Expression). Non-string attributes are not
     * permissions this catalog governs; `Permission::isWellFormed()` then drops the role and authenticated
     * tokens (`ROLE_*`, `IS_AUTHENTICATED_*`, `PUBLIC_ACCESS`), which carry no `resource.action` separator.
     *
     * @param ReflectionAttribute<IsGranted> $attribute
     */
    private function permissionOf(ReflectionAttribute $attribute): ?string
    {
        $declared = $attribute->newInstance()->attribute;

        if (!\is_string($declared)) {
            return null;
        }

        return Permission::isWellFormed($declared) ? $declared : null;
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
        $attributes = $reflectionClass->getAttributes(IsGranted::class);

        foreach ($reflectionClass->getMethods() as $reflectionMethod) {
            $attributes = [...$attributes, ...$reflectionMethod->getAttributes(IsGranted::class)];
        }

        return $attributes;
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
