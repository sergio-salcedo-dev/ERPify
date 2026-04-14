<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankSearcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/banks', name: 'backoffice_banks_get', methods: ['GET'])]
final readonly class BanksGetController
{
    public function __construct(
        private BankSearcher $bankSearcher,
        private SerializerInterface $serializer,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(
            $this->serializer->serialize($this->bankSearcher->search(), 'json', ['groups' => ['bank:read']]),
            \Symfony\Component\HttpFoundation\Response::HTTP_OK,
            [],
            true,
        );
    }
}
