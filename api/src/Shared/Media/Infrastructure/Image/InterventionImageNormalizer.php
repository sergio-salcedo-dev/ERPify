<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Infrastructure\Image;

use Erpify\Shared\Media\Application\Dto\NormalizedImage;
use Erpify\Shared\Media\Application\Port\ImageNormalizer;
use Erpify\Shared\Media\Domain\Exception\InvalidImageException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[AsAlias(ImageNormalizer::class)]
final class InterventionImageNormalizer implements ImageNormalizer
{
    private const ALLOWED_MIMES = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/webp' => true,
    ];

    private ImageManager $manager;

    public function __construct(
        #[Autowire('%erpify.media.max_dimension%')]
        private readonly int $maxDimension,
        #[Autowire('%erpify.media.jpeg_quality%')]
        private readonly int $jpegQuality,
        #[Autowire('%erpify.media.webp_quality%')]
        private readonly int $webpQuality,
    ) {
        $this->manager = new ImageManager(new Driver());
    }

    public function normalize(UploadedFile $file): NormalizedImage
    {
        $mime = $file->getMimeType() ?? '';
        if (!isset(self::ALLOWED_MIMES[$mime])) {
            throw new InvalidImageException(sprintf('Unsupported image MIME type: %s', $mime));
        }

        $binary = (string) file_get_contents($file->getPathname());
        if ($binary === '') {
            throw new InvalidImageException('Empty upload.');
        }

        try {
            $image = $this->manager->read($binary);
        } catch (\Throwable) {
            throw new InvalidImageException('Could not decode image.');
        }

        $image->scaleDown($this->maxDimension, $this->maxDimension);

        $encoded = match ($mime) {
            'image/jpeg' => $image->encode(new JpegEncoder(quality: $this->jpegQuality)),
            'image/png' => $image->encode(new PngEncoder()),
            'image/webp' => $image->encode(new WebpEncoder(quality: $this->webpQuality)),
            default => throw new InvalidImageException(sprintf('Unsupported image MIME type: %s', $mime)),
        };

        $bytes = (string) $encoded;
        $hash = hash('sha256', $bytes);

        return new NormalizedImage($bytes, $mime, $hash);
    }
}
