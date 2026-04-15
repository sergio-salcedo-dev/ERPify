<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankCreator;
use Erpify\Backoffice\Bank\Infrastructure\Request\BankInput;
use Erpify\Shared\Media\Domain\Exception\InvalidImageException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/banks', name: 'backoffice_bank_post', methods: ['POST'])]
final readonly class BankPostController
{
    use ValidationTrait;

    public function __construct(
        private BankCreator $bankCreator,
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
        #[Autowire('%erpify.media.max_upload_bytes%')]
        private string $maxUploadSize,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $contentType = (string) $request->headers->get('Content-Type', '');

        if ($request->files->count() > 0 || \str_contains($contentType, 'multipart/form-data')) {
            return $this->fromMultipart($request);
        }

        return $this->fromJson($request);
    }

    private function fromJson(Request $request): JsonResponse
    {
        try {
            /** @var BankInput $input */
            $input = $this->serializer->deserialize($request->getContent(), BankInput::class, 'json');
        } catch (NotEncodableValueException) {
            return new JsonResponse(['errors' => [['field' => '', 'message' => 'Invalid JSON body.']]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $constraintViolationList = $this->validator->validate($input);

        if (\count($constraintViolationList) > 0) {
            return $this->validationErrorResponse($constraintViolationList);
        }

        $bank = $this->bankCreator->create($input->name, $input->shortName);

        return new JsonResponse(
            $this->serializer->serialize($bank, 'json', ['groups' => ['bank:read']]),
            Response::HTTP_CREATED,
            [],
            true,
        );
    }

    private function fromMultipart(Request $request): JsonResponse
    {
        $bankInput = new BankInput();
        $bankInput->name = (string) $request->request->get('name', '');
        $bankInput->shortName = (string) ($request->request->get('short_name') ?? $request->request->get('shortName', ''));

        $constraintViolationList = $this->validator->validate($bankInput);

        if (\count($constraintViolationList) > 0) {
            return $this->validationErrorResponse($constraintViolationList);
        }

        /** @var UploadedFile|null $image */
        $image = $request->files->get('image');

        /** @var UploadedFile|null $storedObject */
        $storedObject = $request->files->get('stored_object');

        foreach (['image' => $image, 'stored_object' => $storedObject] as $file) {
            if (null === $file) {
                continue;
            }

            $fileViolations = $this->validator->validate($file, [
                new File(
                    maxSize: $this->maxUploadSize,
                    mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                    mimeTypesMessage: 'Upload a JPEG, PNG, or WebP image.',
                ),
            ]);

            if (\count($fileViolations) > 0) {
                return $this->validationErrorResponse($fileViolations);
            }
        }

        try {
            $bank = $this->bankCreator->create($bankInput->name, $bankInput->shortName, $image, $storedObject);
        } catch (InvalidImageException $invalidImageException) {
            return new JsonResponse(
                ['errors' => [['field' => $invalidImageException->formField(), 'message' => $invalidImageException->getMessage()]]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new JsonResponse(
            $this->serializer->serialize($bank, 'json', ['groups' => ['bank:read']]),
            Response::HTTP_CREATED,
            [],
            true,
        );
    }
}
