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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/banks', name: 'backoffice_bank_post', methods: ['POST'])]
final class BankPostController
{
    use ValidationTrait;

    public function __construct(
        private readonly BankCreator $creator,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        #[Autowire('%erpify.media.max_upload_bytes%')]
        private readonly string $maxUploadSize,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        if ($request->files->count() > 0 || str_contains($contentType, 'multipart/form-data')) {
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

    private function fromMultipart(Request $request): JsonResponse
    {
        $input = new BankInput();
        $input->name = (string) $request->request->get('name', '');
        $input->shortName = (string) ($request->request->get('short_name') ?? $request->request->get('shortName', ''));

        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            return $this->validationErrorResponse($violations);
        }

        /** @var UploadedFile|null $image */
        $image = $request->files->get('image');
        if ($image !== null) {
            $fileViolations = $this->validator->validate($image, [
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
            $bank = $this->creator->create($input->name, $input->shortName, $image);
        } catch (InvalidImageException $e) {
            return new JsonResponse(
                ['errors' => [['field' => 'image', 'message' => $e->getMessage()]]],
                422,
            );
        }

        return new JsonResponse(
            $this->serializer->serialize($bank, 'json', ['groups' => ['bank:read']]),
            201,
            [],
            true,
        );
    }
}
