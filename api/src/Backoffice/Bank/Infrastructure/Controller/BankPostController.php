<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankCreator;
use Erpify\Backoffice\Bank\Application\Command\CreateBankCommand;
use Erpify\Backoffice\Bank\Infrastructure\Http\BankResourceMapper;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Media\Application\Dto\UploadedImage;
use Erpify\Shared\Validation\Application\Validator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\MapUploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\File;

#[Route('/banks', name: 'backoffice_bank_create', methods: ['POST'])]
final readonly class BankPostController
{
    public function __construct(
        private BankCreator $bankCreator,
        private BankResourceMapper $bankResourceMapper,
        private ResourceResponder $resourceResponder,
        private Validator $validator,
        #[Autowire('%erpify.media.max_upload_bytes%')]
        private string $maxUploadSize,
    ) {
    }

    public function __invoke(
        #[MapRequestPayload(acceptFormat: ['json', 'form'])]
        CreateBankCommand $bankCommand,
        #[MapUploadedFile(name: 'image')]
        ?UploadedFile $image = null,
        #[MapUploadedFile(name: 'storedObject')]
        ?UploadedFile $storedObject = null,
    ): Response {
        $this->assertValidUpload($image);
        $this->assertValidUpload($storedObject);

        $bank = $this->bankCreator->create(
            $bankCommand,
            $this->toUploadedImage($image),
            $this->toUploadedImage($storedObject),
        );

        return $this->resourceResponder->respond(
            $this->bankResourceMapper->toCreateResource($bank),
            Response::HTTP_CREATED,
        );
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

    private function toUploadedImage(?UploadedFile $file): ?UploadedImage
    {
        if (!$file instanceof UploadedFile) {
            return null;
        }

        return new UploadedImage(
            $file->getMimeType() ?? '',
            (string) \file_get_contents($file->getPathname()),
        );
    }
}
