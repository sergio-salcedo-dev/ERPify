<?php

declare(strict_types=1);

namespace Erpify\Frontoffice\Dev\Infrastructure\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Exposes FrankenPHP's Mercure subscribe URL for file-watch hot reload (dev / FrankenPHP only).
 *
 * @see https://frankenphp.dev/docs/hot-reload/
 */
#[Route('/dev/frankenphp-hot-reload', name: 'frontoffice_dev_frankenphp_hot_reload', methods: ['GET'])]
final class FrankenPhpHotReloadController
{
    public function __invoke(): JsonResponse
    {
        $subscribePath = $_SERVER['FRANKENPHP_HOT_RELOAD'] ?? '';
        if (!\is_string($subscribePath) || '' === $subscribePath) {
            return new JsonResponse(['enabled' => false]);
        }

        return new JsonResponse([
            'enabled' => true,
            'subscribePath' => $subscribePath,
        ]);
    }
}
