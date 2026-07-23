<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Controller;

use Erpify\Backoffice\BankAccount\Application\BankAccountStatusChanger;
use Erpify\Backoffice\BankAccount\Application\Command\ChangeBankAccountStatusCommand;
use Erpify\Backoffice\BankAccount\Infrastructure\Http\BankAccountResourceMapper;
use Erpify\Backoffice\BankAccount\Infrastructure\Security\BankAccountPermission;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

#[Route('/bank-accounts/{id}/status', name: 'backoffice_bank_account_change_status', methods: ['PATCH'])]
#[IsGranted(BankAccountPermission::CHANGE_STATUS)]
final readonly class BankAccountPatchStatusController
{
    public function __construct(
        private BankAccountStatusChanger $bankAccountStatusChanger,
        private BankAccountResourceMapper $bankAccountResourceMapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    /**
     * @throws ExceptionInterface
     * @throws MessengerExceptionInterface
     */
    public function __invoke(
        string $id,
        #[StrictRequestPayload(acceptFormat: ['json'])]
        ChangeBankAccountStatusCommand $command,
    ): Response {
        $account = $this->bankAccountStatusChanger->change($id, $command);

        return $this->resourceResponder->respond(
            $this->bankAccountResourceMapper->toResource($account),
        );
    }
}
