<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\Projection\BankCountReadModel;
use Erpify\Backoffice\Bank\Infrastructure\Security\BankPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Serves the total number of banks from the `bank_count` read model (projection), not `COUNT(*)` —
 * the read path that proves the projector is the source of the total.
 *
 * `priority` keeps this static route ahead of the dynamic `/banks/{id}`, so `count` is never parsed
 * as a bank id.
 */
#[Route('/banks/count', name: 'backoffice_bank_count', methods: ['GET'], priority: 10)]
#[IsGranted(BankPermission::READ)]
final readonly class BankCountController
{
    public function __construct(private BankCountReadModel $bankCountReadModel)
    {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['total' => $this->bankCountReadModel->total()]);
    }
}
