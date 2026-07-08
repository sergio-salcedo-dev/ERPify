<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankDetailFinder;
use Erpify\Backoffice\Bank\Infrastructure\Http\BankResourceMapper;
use Erpify\Backoffice\Bank\Infrastructure\Security\BankPermission;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/banks/{id}', name: 'backoffice_bank_get', defaults: ['_audit_resource_type' => 'Bank'], methods: ['GET'])]
#[IsGranted(BankPermission::READ)]
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
