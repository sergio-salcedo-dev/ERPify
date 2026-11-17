<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankFinder;
use Erpify\Backoffice\Bank\Application\BankLogoSetter;
use Erpify\Backoffice\Bank\Infrastructure\Security\BankPermission;
use Erpify\Shared\Http\Infrastructure\Upload\RawBodyRoute;
use Erpify\Shared\Images\Infrastructure\Http\ImageRequestBodyReader;
use Erpify\Shared\Images\Infrastructure\Http\ImageRequestThrottle;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sets or replaces a bank's logo from the raw bytes of the body. The order is the contract: the caller is
 * authenticated by the firewall and authorised by `bank.write`, the id's syntax is checked and the bank looked up,
 * the identity's write budget is spent, and only then is the body read — so a refusal on any earlier step stores
 * nothing and costs no read.
 */
#[Route(
    '/banks/{id}/logo',
    name: 'backoffice_bank_logo_set',
    defaults: [RawBodyRoute::ATTRIBUTE => true],
    methods: ['PUT'],
)]
#[IsGranted(BankPermission::WRITE)]
final readonly class BankLogoPutController
{
    public function __construct(
        private BankFinder $bankFinder,
        private ImageRequestThrottle $imageRequestThrottle,
        private ImageRequestBodyReader $imageRequestBodyReader,
        private BankLogoSetter $bankLogoSetter,
    ) {
    }

    public function __invoke(string $id, Request $request): JsonResponse
    {
        $bank = $this->bankFinder->find($id);
        $this->imageRequestThrottle->ensureWithinWriteBudget();
        $body = $this->imageRequestBodyReader->read($request);

        $imageId = $this->bankLogoSetter->set((string) $bank->getId(), $body->bytes, $body->declaredMediaType);

        // Bare `{ "imageId" }`, the wire contract of every image write route: the id the bank now holds, never an
        // envelope and never a URL.
        return new JsonResponse(['imageId' => $imageId->toString()]);
    }
}
