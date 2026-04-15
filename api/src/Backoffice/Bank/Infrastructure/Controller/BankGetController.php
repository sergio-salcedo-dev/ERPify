<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankFinder;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;

#[Route('/banks/{id}', name: 'backoffice_bank_get', methods: ['GET'])]
final readonly class BankGetController
{
    public function __construct(
        private BankFinder $bankFinder,
        private SerializerInterface $serializer,
    ) {
    }

    public function __invoke(Uuid $uuid): JsonResponse
    {
        try {
            $bank = $this->bankFinder->find($uuid);
        } catch (BankNotFoundException $bankNotFoundException) {
            return new JsonResponse(['error' => $bankNotFoundException->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(
            $this->serializer->serialize($bank, 'json', ['groups' => ['bank:read']]),
            Response::HTTP_OK,
            [],
            true,
        );
    }
}
