<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Backoffice\Bank\Infrastructure\Controller\BankRealtimeAuthorizeController;
use Erpify\Iam\Identity\Infrastructure\Security\Permission;
use Erpify\Iam\Identity\Infrastructure\Security\PermissionCatalog;
use Erpify\Tests\Support\ApiSourceFiles;
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
            'Precondition drifted: this controller is now gated at class level, so it no longer exercises\n'
            . 'the method path.',
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
