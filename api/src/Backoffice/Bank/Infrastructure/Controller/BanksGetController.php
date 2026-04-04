<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankSearcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/banks', name: 'backoffice_banks_get', methods: ['GET'])]
final class BanksGetController
{
    public function __construct(
        private readonly BankSearcher $searcher,
        private readonly SerializerInterface $serializer,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(
            $this->serializer->serialize($this->searcher->search(), 'json', ['groups' => ['bank:read']]),
            200,
            [],
            true,
        );
    }
}
