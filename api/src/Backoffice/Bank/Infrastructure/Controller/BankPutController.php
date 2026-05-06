<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankUpdater;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Infrastructure\Request\BankPutPayload;
use Erpify\Shared\Application\UseCase\Result;
use Erpify\Shared\Infrastructure\Http\JsonApiErrorBuilder;
use Erpify\Shared\Infrastructure\Http\Responder\ResponderInterface;
use Erpify\Shared\Infrastructure\Serializer\ResourceNormalizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;

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
    public function __invoke(string $id, #[MapRequestPayload] BankPutPayload $input): Response
    {
        try {
            $bank = $this->bankUpdater->update($id, $input->name, $input->shortName);
        } catch (ValidationFailedException $validationFailedException) {
            return new JsonResponse(
                JsonApiErrorBuilder::fromViolations($validationFailedException->getViolations(), 'uuid'),
                Response::HTTP_BAD_REQUEST,
            );
        } catch (BankNotFoundException $bankNotFoundException) {
            return new JsonResponse(
                JsonApiErrorBuilder::envelope([
                    JsonApiErrorBuilder::error('uuid', $bankNotFoundException->getMessage()),
                ]),
                Response::HTTP_NOT_FOUND,
            );
        }

        $data = $this->resourceNormalizer->toArray(
            $bank,
            ['identifiable', 'timestamped', 'bank:get'],
        );

        return $this->responder->respond(Result::ok($data));
    }
}
