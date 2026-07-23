<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankCreator;
use Erpify\Backoffice\Bank\Application\Command\CreateBankCommand;
use Erpify\Backoffice\Bank\Infrastructure\Http\BankResourceMapper;
use Erpify\Backoffice\Bank\Infrastructure\Security\BankPermission;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/banks', name: 'backoffice_bank_create', methods: ['POST'])]
#[IsGranted(BankPermission::WRITE)]
final readonly class BankPostController
{
    public function __construct(
        private BankCreator $bankCreator,
        private BankResourceMapper $bankResourceMapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    public function __invoke(
        #[StrictRequestPayload(acceptFormat: ['json'])]
        CreateBankCommand $bankCommand,
    ): Response {
        $bank = $this->bankCreator->create($bankCommand);

        return $this->resourceResponder->respond(
            $this->bankResourceMapper->toCreateResource($bank),
            Response::HTTP_CREATED,
        );
    }
}
