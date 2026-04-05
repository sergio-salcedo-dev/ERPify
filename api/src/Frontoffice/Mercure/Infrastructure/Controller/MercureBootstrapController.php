<?php

declare(strict_types=1);

namespace Erpify\Frontoffice\Mercure\Infrastructure\Controller;

use Erpify\Frontoffice\Mercure\Domain\MercureDemoTopic;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\Discovery;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/mercure/bootstrap', name: 'frontoffice_mercure_bootstrap', methods: ['GET'])]
final class MercureBootstrapController
{
    public function __invoke(
        Request $request,
        Discovery $discovery,
        Authorization $authorization,
        #[Autowire(env: 'MERCURE_PUBLIC_URL')]
        string $mercurePublicUrl,
    ): JsonResponse {
        $discovery->addLink($request);
        $authorization->setCookie($request, [MercureDemoTopic::URI]);

        return new JsonResponse([
            'hubUrl' => rtrim($mercurePublicUrl, '/'),
            'topic' => MercureDemoTopic::URI,
        ]);
    }
}
