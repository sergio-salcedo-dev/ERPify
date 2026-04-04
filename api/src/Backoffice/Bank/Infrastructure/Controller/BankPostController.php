<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankCreator;
use Erpify\Backoffice\Bank\Infrastructure\Request\BankInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/banks', name: 'backoffice_bank_post', methods: ['POST'])]
final class BankPostController
{
    use ValidationTrait;

    public function __construct(
        private readonly BankCreator $creator,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            /** @var BankInput $input */
            $input = $this->serializer->deserialize($request->getContent(), BankInput::class, 'json');
        } catch (NotEncodableValueException) {
            return new JsonResponse(['errors' => [['field' => '', 'message' => 'Invalid JSON body.']]], 422);
        }

        $violations = $this->validator->validate($input);
        if (count($violations) > 0) {
            return $this->validationErrorResponse($violations);
        }

        $bank = $this->creator->create($input->name, $input->shortName);

        return new JsonResponse(
            $this->serializer->serialize($bank, 'json', ['groups' => ['bank:read']]),
            201,
            [],
            true,
        );
    }
}
