<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Serializer;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Media\Application\Port\MediaPublicUrlGenerator;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Storage\Application\Port\StoredObjectPublicUrlGenerator;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
#[AutoconfigureTag('serializer.normalizer', ['priority' => 100])]
final class BankLogoUrlNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const string MARK = 'bank_logo_url_normalizer';

    public function __construct(
        private readonly MediaPublicUrlGenerator $mediaPublicUrlGenerator,
        private readonly StoredObjectPublicUrlGenerator $storedObjectPublicUrlGenerator,
    ) {
    }

    #[Override]
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $context[self::MARK] = true;
        $normalizedData = $this->normalizer->normalize($data, $format, $context);
        \assert(\is_array($normalizedData));

        $groups = \is_array($context['groups'] ?? null) ? $context['groups'] : [];

        if ($data instanceof Bank && \in_array('bank:read:urls', $groups, true)) {
            $logo = $data->getLogo();
            $normalizedData['logoUrl'] = $logo instanceof Media
                ? $this->mediaPublicUrlGenerator->urlForContentHash($logo->getContentHash())
                : null;
            $storedHash = $data->getStoredObjectContentHash();
            $normalizedData['storedObjectUrl'] = null !== $storedHash
                ? $this->storedObjectPublicUrlGenerator->urlForContentHash($storedHash)
                : null;
        }

        return $normalizedData;
    }

    #[Override]
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        $groups = \is_array($context['groups'] ?? null) ? $context['groups'] : [];

        return $data instanceof Bank
            && \in_array('bank:read:urls', $groups, true)
            && !isset($context[self::MARK]);
    }

    /**
     * @return array<string, bool>
     */
    #[Override]
    public function getSupportedTypes(?string $format): array
    {
        return [
            Bank::class => false,
        ];
    }
}
