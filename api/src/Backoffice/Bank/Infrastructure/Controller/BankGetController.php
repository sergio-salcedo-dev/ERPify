<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankFinder;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Shared\Application\UseCase\Result;
use Erpify\Shared\Infrastructure\Http\JsonApiErrorBuilder;
use Erpify\Shared\Infrastructure\Http\Responder\ResponderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/banks/{id}', name: 'backoffice_bank_get', methods: ['GET'])]
final readonly class BankGetController
{
    public function __construct(
        private BankFinder $bankFinder,
        private SerializerInterface $serializer,
        private ResponderInterface $responder,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $value = 'null' === $id ? '' : $id;
        $constraintViolationList = $this->validator->validate($value, [new Assert\NotBlank(), new Assert\Uuid()]);

        if (\count($constraintViolationList) > 0) {
            return new JsonResponse(
                JsonApiErrorBuilder::fromViolations($constraintViolationList, 'id'),
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $bank = $this->bankFinder->find(Uuid::fromString($id));
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
            $this->serializer->serialize($bank, 'json', ['groups' => ['aggregate:default', 'bank:get', 'bank:read:urls']]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $this->responder->respond(Result::ok($data));
    }
}
