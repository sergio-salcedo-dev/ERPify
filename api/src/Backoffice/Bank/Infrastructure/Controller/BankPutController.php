<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankUpdater;
use Erpify\Backoffice\Bank\Application\Command\UpdateBankCommand;
use Erpify\Backoffice\Bank\Infrastructure\Http\BankResourceMapper;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

#[Route('/banks/{id}', name: 'backoffice_bank_update', methods: ['PUT'])]
final readonly class BankPutController
{
    public function __construct(
        private BankUpdater $bankUpdater,
        private BankResourceMapper $bankResourceMapper,
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
        UpdateBankCommand $bankCommand,
    ): Response {
        $bank = $this->bankUpdater->update($id, $bankCommand);

        return $this->resourceResponder->respond(
            $this->bankResourceMapper->toUpdateResource($bank),
        );
    }
}
