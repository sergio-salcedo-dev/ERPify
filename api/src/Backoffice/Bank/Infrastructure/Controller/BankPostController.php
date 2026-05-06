<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankCreator;
use Erpify\Backoffice\Bank\Infrastructure\Request\BankPostPayload;
use Erpify\Shared\Application\UseCase\Result;
use Erpify\Shared\Application\Validation\Validator;
use Erpify\Shared\Infrastructure\Http\JsonApiErrorBuilder;
use Erpify\Shared\Infrastructure\Http\Responder\ResponderInterface;
use Erpify\Shared\Infrastructure\Serializer\ResourceNormalizer;
use Erpify\Shared\Media\Domain\Exception\InvalidImageException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\MapUploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\File;

#[Route('/banks', name: 'backoffice_bank_post', methods: ['POST'])]
final readonly class BankPostController
{
    public function __construct(
        private BankCreator $bankCreator,
        private ResourceNormalizer $resourceNormalizer,
        private Validator $validator,
        private ResponderInterface $responder,
        #[Autowire('%erpify.media.max_upload_bytes%')]
        private string $maxUploadSize,
    ) {
    }

    public function __invoke(
        #[MapRequestPayload(acceptFormat: ['json', 'form'])]
        BankPostPayload $input,
        #[MapUploadedFile(name: 'image')]
        ?UploadedFile $image = null,
        #[MapUploadedFile(name: 'storedObject')]
        ?UploadedFile $storedObject = null,
    ): Response {
        $this->assertValidUpload($image);
        $this->assertValidUpload($storedObject);

        try {
            $bank = $this->bankCreator->create($input->name, $input->shortName, $image, $storedObject);
        } catch (InvalidImageException $invalidImageException) {
            return new JsonResponse(
                JsonApiErrorBuilder::envelope([
                    JsonApiErrorBuilder::error(
                        $invalidImageException->formField(),
                        $invalidImageException->getMessage(),
                    ),
                ]),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $data = $this->resourceNormalizer->toArray(
            $bank,
            ['identifiable', 'timestamped', 'bank:get', 'bank:read:urls'],
        );

        return $this->responder->respond(Result::created($data));
    }

    private function assertValidUpload(?UploadedFile $file): void
    {
        if (!$file instanceof UploadedFile) {
            return;
        }

        $this->validator->ensure($file, [
            new File(
                maxSize: $this->maxUploadSize,
                mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                mimeTypesMessage: 'Upload a JPEG, PNG, or WebP image.',
            ),
        ]);
    }
}
