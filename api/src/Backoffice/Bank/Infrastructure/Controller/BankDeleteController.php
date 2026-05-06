<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankDeleter;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Shared\Application\UseCase\Result;
use Erpify\Shared\Infrastructure\Http\JsonApiErrorBuilder;
use Erpify\Shared\Infrastructure\Http\Responder\ResponderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;

#[Route('/banks/{id}', name: 'backoffice_bank_delete', methods: ['DELETE'])]
final readonly class BankDeleteController
{
    public function __construct(
        private BankDeleter $bankDeleter,
        private ResponderInterface $responder,
    ) {
    }

    public function __invoke(string $id): Response
    {
        try {
            $this->bankDeleter->delete($id);
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

        return $this->responder->respond(Result::noContent());
    }
}
