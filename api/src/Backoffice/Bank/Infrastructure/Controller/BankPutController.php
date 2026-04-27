<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankUpdater;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Infrastructure\Request\BankInput;
use Erpify\Shared\Application\UseCase\Result;
use Erpify\Shared\Infrastructure\Http\JsonApiErrorBuilder;
use Erpify\Shared\Infrastructure\Http\Responder\ResponderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/banks/{id}', name: 'backoffice_bank_put', methods: ['PUT'])]
final readonly class BankPutController
{
    use ValidationTrait;

    public function __construct(
        private BankUpdater $bankUpdater,
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
        private ResponderInterface $responder,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        $value = 'null' === $id ? '' : $id;
        $idViolations = $this->validator->validate($value, [new Assert\NotBlank(), new Assert\Uuid()]);

        if (\count($idViolations) > 0) {
            return new JsonResponse(
                JsonApiErrorBuilder::fromViolations($idViolations, 'uuid'),
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $input = $this->serializer->deserialize($request->getContent(), BankInput::class, 'json');
        } catch (NotEncodableValueException) {
            return new JsonResponse(
                JsonApiErrorBuilder::envelope([
                    JsonApiErrorBuilder::error('', 'Invalid JSON body.'),
                ]),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $constraintViolationList = $this->validator->validate($input);

        if (\count($constraintViolationList) > 0) {
            return $this->validationErrorResponse($constraintViolationList);
        }

        try {
            $bank = $this->bankUpdater->update(Uuid::fromString($id), $input->name, $input->shortName);
        } catch (BankNotFoundException $bankNotFoundException) {
            return new JsonResponse(
                JsonApiErrorBuilder::envelope([
                    JsonApiErrorBuilder::error('uuid', $bankNotFoundException->getMessage()),
                ]),
                Response::HTTP_NOT_FOUND,
            );
        }

        /** @var array<string, mixed> $data */
        $data = \json_decode(
            $this->serializer->serialize($bank, 'json', ['groups' => ['bank:read']]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $this->responder->respond(Result::ok($data));
    }
}
