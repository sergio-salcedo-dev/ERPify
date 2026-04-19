<?php

declare(strict_types=1);

namespace Erpify\Frontoffice\Dev\Infrastructure\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    path: '/dev/frankenphp-hot-reload',
    name: 'frontoffice_dev_frankenphp_hot_reload',
    methods: ['GET'],
)]
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
