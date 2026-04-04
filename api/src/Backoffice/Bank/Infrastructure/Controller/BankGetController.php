<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankFinder;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;

#[Route('/banks/{id}', name: 'backoffice_bank_get', methods: ['GET'])]
final class BankGetController
{
    public function __construct(
        private readonly BankFinder $finder,
        private readonly SerializerInterface $serializer,
    ) {
    }

    public function __invoke(Uuid $id): JsonResponse
    {
        try {
            $bank = $this->finder->find($id);
        } catch (BankNotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }

        return new JsonResponse(
            $this->serializer->serialize($bank, 'json', ['groups' => ['bank:read']]),
            200,
            [],
            true,
        );
    }
}
