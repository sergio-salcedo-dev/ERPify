<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankUpdater;
use Erpify\Backoffice\Bank\Application\Command\UpdateBankCommand;
use Erpify\Shared\Application\UseCase\Result;
use Erpify\Shared\Infrastructure\Http\Responder\ResponderInterface;
use Erpify\Shared\Infrastructure\Serializer\ResourceNormalizer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/banks/{id}', name: 'backoffice_bank_put', methods: ['PUT'])]
final readonly class BankPutController
{
    public function __construct(
        private BankUpdater $bankUpdater,
        private ResourceNormalizer $resourceNormalizer,
        private ResponderInterface $responder,
    ) {
    }

    /**
     * @throws MessengerExceptionInterface
     */
    public function __invoke(string $id, #[MapRequestPayload] UpdateBankCommand $bankCommand): Response
    {
        $bank = $this->bankUpdater->update($id, $bankCommand);

        $data = $this->resourceNormalizer->toArray(
            $bank,
            ['identifiable', 'timestamped', 'bank:get'],
        );

        return $this->responder->respond(Result::ok($data));
    }
}
