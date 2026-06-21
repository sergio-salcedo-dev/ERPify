<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankDetailFinder;
use Erpify\Backoffice\Bank\Infrastructure\Http\BankResourceMapper;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/banks/{id}', name: 'backoffice_bank_get', methods: ['GET'])]
final readonly class BankGetController
{
    public function __construct(
        private BankDetailFinder $bankDetailFinder,
        private BankResourceMapper $bankResourceMapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    public function __invoke(string $id): Response
    {
        return $this->resourceResponder->respond(
            $this->bankResourceMapper->toDetailResource($this->bankDetailFinder->find($id)),
        );
    }
}
