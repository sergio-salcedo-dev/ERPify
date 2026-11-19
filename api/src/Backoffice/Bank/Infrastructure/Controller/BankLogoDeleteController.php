<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankFinder;
use Erpify\Backoffice\Bank\Application\BankLogoRemover;
use Erpify\Backoffice\Bank\Infrastructure\Security\BankPermission;
use Erpify\Shared\Http\Infrastructure\Responder\ResponderInterface;
use Erpify\Shared\Images\Infrastructure\Http\ImageRequestThrottle;
use Erpify\Shared\Kernel\Application\Result;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Removes a bank's logo. Idempotent: `204` whether or not it held one, and nothing is queued when it held none.
 */
#[Route('/banks/{id}/logo', name: 'backoffice_bank_logo_remove', methods: ['DELETE'])]
#[IsGranted(BankPermission::WRITE)]
final readonly class BankLogoDeleteController
{
    public function __construct(
        private BankFinder $bankFinder,
        private ImageRequestThrottle $imageRequestThrottle,
        private BankLogoRemover $bankLogoRemover,
        private ResponderInterface $responder,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $bank = $this->bankFinder->find($id);
        $this->imageRequestThrottle->ensureWithinWriteBudget();

        $this->bankLogoRemover->remove((string) $bank->getId());

        return $this->responder->respond(Result::noContent());
    }
}
