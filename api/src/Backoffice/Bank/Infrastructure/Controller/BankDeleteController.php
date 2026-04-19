<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankDeleter;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/banks/{id}', name: 'backoffice_bank_delete', methods: ['DELETE'])]
final readonly class BankDeleteController
{
    public function __construct(private BankDeleter $bankDeleter)
    {
    }

    public function __invoke(Uuid $id): JsonResponse
    {
        try {
            $this->bankDeleter->delete($id);
        } catch (BankNotFoundException $bankNotFoundException) {
            return new JsonResponse(['error' => $bankNotFoundException->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
