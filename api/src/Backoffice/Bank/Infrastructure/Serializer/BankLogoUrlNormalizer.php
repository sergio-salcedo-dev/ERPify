<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Serializer;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Media\Application\Port\MediaPublicUrlGenerator;
use Erpify\Shared\Storage\Application\Port\StoredObjectPublicUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

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

    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        $context[self::MARK] = true;
        $data = $this->normalizer->normalize($object, $format, $context);
        \assert(\is_array($data));

        if ($object instanceof Bank && \in_array('bank:read', $context['groups'] ?? [], true)) {
            $logo = $object->getLogo();
            $data['logoUrl'] = $logo instanceof \Erpify\Shared\Media\Domain\Entity\Media ? $this->mediaPublicUrlGenerator->urlForContentHash($logo->getContentHash()) : null;
            $storedHash = $object->getStoredObjectContentHash();
            $data['storedObjectUrl'] = $storedHash !== null
                ? $this->storedObjectPublicUrlGenerator->urlForContentHash($storedHash)
                : null;
        }

        return $data;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Bank
            && \in_array('bank:read', $context['groups'] ?? [], true)
            && !isset($context[self::MARK]);
    }

    /**
     * @return array<string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            Bank::class => false,
        ];
    }
}
