<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankDeleter;
use Erpify\Shared\Http\Infrastructure\Responder\ResponderInterface;
use Erpify\Shared\Kernel\Application\Result;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/banks/{id}', name: 'backoffice_bank_delete', methods: ['DELETE'])]
final readonly class BankDeleteController
{
    public function __construct(
        private BankDeleter $bankDeleter,
        private ResponderInterface $responder,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $this->bankDeleter->delete($id);

        return $this->responder->respond(Result::noContent());
    }
}
