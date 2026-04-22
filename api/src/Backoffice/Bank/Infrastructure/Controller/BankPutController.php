<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankUpdater;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Infrastructure\Request\BankInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/banks/{id}', name: 'backoffice_bank_put', methods: ['PUT'])]
final readonly class BankPutController
{
    use ValidationTrait;

    public function __construct(
        private BankUpdater $bankUpdater,
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(Uuid $uuid, Request $request): JsonResponse
    {
        try {
            $input = $this->serializer->deserialize($request->getContent(), BankInput::class, 'json');
        } catch (NotEncodableValueException) {
            return new JsonResponse(
                ['errors' => [['field' => '', 'message' => 'Invalid JSON body.']]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $constraintViolationList = $this->validator->validate($input);

        if (\count($constraintViolationList) > 0) {
            return $this->validationErrorResponse($constraintViolationList);
        }

        try {
            $bank = $this->bankUpdater->update($uuid, $input->name, $input->shortName);
        } catch (BankNotFoundException $bankNotFoundException) {
            return new JsonResponse(['error' => $bankNotFoundException->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(
            $this->serializer->serialize($bank, 'json', ['groups' => ['bank:read']]),
            Response::HTTP_OK,
            [],
            true,
        );
    }
}
