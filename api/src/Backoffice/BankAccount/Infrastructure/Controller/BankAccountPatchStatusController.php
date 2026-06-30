<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Controller;

use Erpify\Backoffice\BankAccount\Application\BankAccountStatusChanger;
use Erpify\Backoffice\BankAccount\Application\Command\ChangeBankAccountStatusCommand;
use Erpify\Backoffice\BankAccount\Infrastructure\Http\BankAccountResourceMapper;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

#[Route('/bank-accounts/{id}/status', name: 'backoffice_bank_account_change_status', methods: ['PATCH'])]
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
        #[MapRequestPayload]
        ChangeBankAccountStatusCommand $command,
    ): Response {
        $account = $this->bankAccountStatusChanger->change($id, $command);

        return $this->resourceResponder->respond(
            $this->bankAccountResourceMapper->toResource($account),
        );
    }
}
