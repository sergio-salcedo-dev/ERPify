<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Infrastructure\Http;

use Erpify\Shared\Infrastructure\Http\ContentHashUrlGenerator;
use Erpify\Shared\Storage\Application\Port\StoredObjectPublicUrlGenerator;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(StoredObjectPublicUrlGenerator::class)]
final readonly class ConfigurableStoredObjectPublicUrlGenerator implements StoredObjectPublicUrlGenerator
{
    public function __construct(
        private ContentHashUrlGenerator $urlGenerator,
    ) {
    }

    #[Override]
    public function urlForContentHash(string $contentHash): string
    {
        return $this->urlGenerator->generate($contentHash, 'shared_stored_object_get');
    }
}
