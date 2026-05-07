<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankFinder;
use Erpify\Shared\Application\UseCase\Result;
use Erpify\Shared\Infrastructure\Http\Responder\ResponderInterface;
use Erpify\Shared\Infrastructure\Serializer\ResourceNormalizer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/banks/{id}', name: 'backoffice_bank_get', methods: ['GET'])]
final readonly class BankGetController
{
    public function __construct(
        private BankFinder $bankFinder,
        private ResourceNormalizer $resourceNormalizer,
        private ResponderInterface $responder,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $bank = $this->bankFinder->find($id);

        $data = $this->resourceNormalizer->toArray(
            $bank,
            ['identifiable', 'timestamped', 'bank:get', 'bank:read:urls'],
        );

        return $this->responder->respond(Result::ok($data));
    }
}
