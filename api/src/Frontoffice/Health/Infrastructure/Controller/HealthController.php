<?php

declare(strict_types=1);

namespace Erpify\Frontoffice\Health\Infrastructure\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/health', name: 'frontoffice_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'service' => 'Front office',
            'datetime' => new DateTimeImmutable()->format(DateTimeInterface::ATOM),
        ]);
    }
}
