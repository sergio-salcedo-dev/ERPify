<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Controller;

use Erpify\Backoffice\BankAccount\Application\BankAccountUpdater;
use Erpify\Backoffice\BankAccount\Application\Command\UpdateBankAccountCommand;
use Erpify\Backoffice\BankAccount\Infrastructure\Http\BankAccountResourceMapper;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

#[Route('/bank-accounts/{id}', name: 'backoffice_bank_account_update', methods: ['PUT'])]
final readonly class BankAccountPutController
{
    public function __construct(
        private BankAccountUpdater $bankAccountUpdater,
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
        UpdateBankAccountCommand $command,
    ): Response {
        $account = $this->bankAccountUpdater->update($id, $command);

        return $this->resourceResponder->respond(
            $this->bankAccountResourceMapper->toResource($account),
        );
    }
}
