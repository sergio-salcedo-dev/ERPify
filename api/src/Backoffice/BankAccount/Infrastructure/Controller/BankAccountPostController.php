<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Controller;

use Erpify\Backoffice\BankAccount\Application\BankAccountCreator;
use Erpify\Backoffice\BankAccount\Application\Command\CreateBankAccountCommand;
use Erpify\Backoffice\BankAccount\Infrastructure\Http\BankAccountResourceMapper;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * Public like the rest of `/backoffice` (the repo has no auth yet); it accepts the PII IBAN — see
 * PRODUCTION_SECURITY_CHECKLIST.md and the auth follow-up.
 */
#[Route('/bank-accounts', name: 'backoffice_bank_account_create', methods: ['POST'])]
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
        #[MapRequestPayload]
        CreateBankAccountCommand $command,
    ): Response {
        $account = $this->bankAccountCreator->create($command);

        return $this->resourceResponder->respond(
            $this->bankAccountResourceMapper->toResource($account),
            Response::HTTP_CREATED,
        );
    }
}
