<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\Projection\BankCountReadModel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the total number of banks from the `bank_count` read model (projection), not `COUNT(*)` —
 * the read path that proves the projector is the source of the total. Public route, consciously
 * unauthenticated for now (consistent with the other `/banks` endpoints).
 *
 * `priority` keeps this static route ahead of the dynamic `/banks/{id}`, so `count` is never parsed
 * as a bank id.
 */
#[Route('/banks/count', name: 'backoffice_bank_count', methods: ['GET'], priority: 10)]
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
