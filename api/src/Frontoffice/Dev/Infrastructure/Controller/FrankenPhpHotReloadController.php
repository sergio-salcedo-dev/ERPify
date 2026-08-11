<?php

declare(strict_types=1);

namespace Erpify\Frontoffice\Dev\Infrastructure\Controller;

use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The #[When] attributes keep the service out of the prod container. They do not, on their own, keep its
 * route out: `config/routes.yaml` loads `src/Frontoffice/` as an attribute resource with no environment
 * condition, so a #[Route] here would be registered in production against a controller the container no
 * longer holds. The route is therefore declared in `config/routes/dev.yaml` and mirrored under `when@test`
 * in `config/routes/test.yaml` for the functional test — the same shape as WebDebugToolbarLoaderController.
 */
#[When(env: 'dev')]
#[When(env: 'test')]
final class FrankenPhpHotReloadController
{
    public function __invoke(Request $request): JsonResponse
    {
        $subscribePath = $request->server->get('FRANKENPHP_HOT_RELOAD', '');

        if (!\is_string($subscribePath) || '' === $subscribePath) {
            return new JsonResponse(['enabled' => false]);
        }

        return new JsonResponse([
            'enabled' => true,
            'subscribePath' => $subscribePath,
        ]);
    }
}
