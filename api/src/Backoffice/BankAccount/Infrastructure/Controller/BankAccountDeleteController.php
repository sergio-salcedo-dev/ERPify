<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Controller;

use Erpify\Backoffice\BankAccount\Application\BankAccountDeleter;
use Erpify\Backoffice\BankAccount\Infrastructure\Security\BankAccountPermission;
use Erpify\Shared\Http\Infrastructure\Responder\ResponderInterface;
use Erpify\Shared\Kernel\Application\Result;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/bank-accounts/{id}', name: 'backoffice_bank_account_delete', methods: ['DELETE'])]
#[IsGranted(BankAccountPermission::DELETE)]
final readonly class BankAccountDeleteController
{
    public function __construct(
        private BankAccountDeleter $bankAccountDeleter,
        private ResponderInterface $responder,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $this->bankAccountDeleter->delete($id);

        return $this->responder->respond(Result::noContent());
    }
}
