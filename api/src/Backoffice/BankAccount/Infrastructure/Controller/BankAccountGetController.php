<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Controller;

use Erpify\Backoffice\BankAccount\Application\BankAccountFinder;
use Erpify\Backoffice\BankAccount\Infrastructure\Http\BankAccountResourceMapper;
use Erpify\Backoffice\BankAccount\Infrastructure\Security\BankAccountPermission;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    '/bank-accounts/{id}',
    name: 'backoffice_bank_account_get',
    defaults: ['_audit_resource_type' => 'BankAccount'],
    methods: ['GET'],
)]
#[IsGranted(BankAccountPermission::READ)]
final readonly class BankAccountGetController
{
    public function __construct(
        private BankAccountFinder $bankAccountFinder,
        private BankAccountResourceMapper $bankAccountResourceMapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    public function __invoke(string $id): Response
    {
        return $this->resourceResponder->respond(
            $this->bankAccountResourceMapper->toResource($this->bankAccountFinder->find($id)),
        );
    }
}
