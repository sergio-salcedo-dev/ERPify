<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankDetailFinder;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Infrastructure\Http\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/banks/{id}', name: 'backoffice_bank_get', methods: ['GET'])]
final readonly class BankGetController
{
    public function __construct(
        private BankDetailFinder $bankDetailFinder,
        private ResourceResponder $resourceResponder,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $bank = $this->bankDetailFinder->find($id);

        return $this->resourceResponder->respond(
            $bank,
            [
                Bank::GROUP_IDENTIFIABLE,
                Bank::GROUP_TIMESTAMPED,
                Bank::GROUP_DETAIL,
                Bank::GROUP_READ_URLS,
                Bank::GROUP_ACCOUNT_COUNT,
            ],
        );
    }
}
