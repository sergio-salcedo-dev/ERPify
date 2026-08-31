<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Images;

use Erpify\Shared\Audit\Domain\AuditPolicy;
use Erpify\Shared\Audit\Domain\HttpInteraction;
use Erpify\Shared\Images\Infrastructure\Controller\ImageGetController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * The route AS THE ROUTER COMPILED IT, which is the only reading that can be trusted here.
 *
 * Reflecting the `#[Route]` attribute would prove the developer's intent and nothing else: until this story
 * no routing resource covered `src/Shared/` at all, so an attribute there registered NOTHING while every
 * gate in the tree stayed green. And the two defaults that matter most are contributed by the resource
 * rather than by the attribute, so they are only visible from this side.
 *
 * @internal
 */
#[CoversClass(ImageGetController::class)]
final class ImageRouteDeclarationTest extends KernelTestCase
{
    private const string ROUTE_NAME = 'shared_image_get';

    public function testTheRouteIsRegisteredUnderTheApiPrefixTheFirewallCovers(): void
    {
        // `/api/v1` is what puts it under the `^/api` catch-all, so it needs no `access_control` line of its
        // own. The path the epic first wrote — `/images/{imageId}` — would have been anonymous by
        // construction.
        $route = $this->route();

        $this->assertSame('/api/v1/images/{imageId}', $route->getPath());
        $this->assertSame(['GET'], $route->getMethods());
    }

    /**
     * The name is a requirement, not a style choice, and this asks the POLICY rather than the string:
     * `AuditPolicy` records every successful `GET` under `/api/` unless the route name matches one of its
     * five non-business shapes. Resolving the route first is what makes the question about the name that is
     * actually REGISTERED; matching a prefix by hand would pass over a policy that stopped reading it.
     */
    public function testTheRegisteredRouteNameIsExcludedFromTheGenericActivityAudit(): void
    {
        $this->route();

        $decision = (new AuditPolicy())->decide(new HttpInteraction(self::ROUTE_NAME, 'GET', false));

        $this->assertFalse($decision->isAuditable(), \sprintf(
            'Route "%s" would be audited generically, so every successful read would write an audit_log row.',
            self::ROUTE_NAME,
        ));
    }

    /**
     * Asserted by EQUALITY with the expected set rather than by the absence of two keys. Enumerating
     * absences passes over a third audit-affecting default added later, which is exactly the shape of
     * regression this assertion exists to catch.
     */
    public function testTheRouteDeclaresExactlyTheDefaultsItIsMeantTo(): void
    {
        $this->assertSame(
            ['_controller' => ImageGetController::class, '_format' => 'json'],
            $this->route()->getDefaults(),
        );
    }

    /**
     * A `requirements` pattern on the identifier would have the ROUTER answer 404 for a malformed one,
     * conflating "you asked wrongly" with "there is nothing there". It has to reach the controller for
     * `ImageId::fromString()` to answer 400.
     */
    public function testTheIdentifierCarriesNoRouterLevelPattern(): void
    {
        $this->assertSame([], $this->route()->getRequirements());
    }

    private function route(): Route
    {
        self::bootKernel();

        $router = self::getContainer()->get(RouterInterface::class);
        $this->assertInstanceOf(RouterInterface::class, $router);

        $route = $router->getRouteCollection()->get(self::ROUTE_NAME);

        $this->assertInstanceOf(Route::class, $route, \sprintf(
            'Route "%s" is not registered. Without a routing resource covering the module the #[Route] '
            . 'attribute registers nothing at all.',
            self::ROUTE_NAME,
        ));

        return $route;
    }
}
