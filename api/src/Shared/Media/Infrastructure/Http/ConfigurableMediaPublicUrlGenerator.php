<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Infrastructure\Http;

use Erpify\Shared\Http\Infrastructure\ContentHashUrlGenerator;
use Erpify\Shared\Media\Application\Port\MediaPublicUrlGenerator;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(MediaPublicUrlGenerator::class)]
final readonly class ConfigurableMediaPublicUrlGenerator implements MediaPublicUrlGenerator
{
    public function __construct(
        private ContentHashUrlGenerator $urlGenerator,
    ) {
    }

    #[Override]
    public function urlForContentHash(string $contentHash): string
    {
        return $this->urlGenerator->generate($contentHash, 'shared_media_get');
    }
}
