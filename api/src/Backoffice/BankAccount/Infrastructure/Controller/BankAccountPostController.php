<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Controller;

use Erpify\Backoffice\BankAccount\Application\BankAccountCreator;
use Erpify\Backoffice\BankAccount\Application\Command\CreateBankAccountCommand;
use Erpify\Backoffice\BankAccount\Infrastructure\Http\BankAccountResourceMapper;
use Erpify\Backoffice\BankAccount\Infrastructure\Security\BankAccountPermission;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * Accepts the PII IBAN and is gated by `bankAccount.write` — see PRODUCTION_SECURITY_CHECKLIST.md.
 */
#[Route('/bank-accounts', name: 'backoffice_bank_account_create', methods: ['POST'])]
#[IsGranted(BankAccountPermission::WRITE)]
final readonly class BankAccountPostController
{
    public function __construct(
        private BankAccountCreator $bankAccountCreator,
        private BankAccountResourceMapper $bankAccountResourceMapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    /**
     * @throws ExceptionInterface
     * @throws MessengerExceptionInterface
     */
    public function __invoke(
        #[StrictRequestPayload(acceptFormat: ['json'])]
        CreateBankAccountCommand $command,
    ): Response {
        $account = $this->bankAccountCreator->create($command);

        return $this->resourceResponder->respond(
            $this->bankAccountResourceMapper->toResource($account),
            Response::HTTP_CREATED,
        );
    }
}
