<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Http;

use DateTimeInterface;
use Erpify\Backoffice\Bank\Application\BankWithAccountCount;
use Erpify\Backoffice\Bank\Application\Resource\BankCreateResource;
use Erpify\Backoffice\Bank\Application\Resource\BankDetailResource;
use Erpify\Backoffice\Bank\Application\Resource\BankListResource;
use Erpify\Backoffice\Bank\Application\Resource\BankUpdateResource;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Media\Application\Port\MediaPublicUrlGenerator;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Storage\Application\Port\StoredObjectPublicUrlGenerator;
use LogicException;

/**
 * Maps a {@see Bank} aggregate (and its read-side account count, when present) to the per-view
 * resource DTO that is actually serialized. This is the single place that knows the wire shape of
 * each bank view, including the logo / stored-object URL synthesis: it injects the same public-URL
 * PORTS the old serializer normalizer used (never the concrete content-hash generator), so the
 * route-name knowledge stays behind the port. The Bank entity therefore never reaches the serializer.
 */
final readonly class BankResourceMapper
{
    public function __construct(
        private MediaPublicUrlGenerator $mediaPublicUrlGenerator,
        private StoredObjectPublicUrlGenerator $storedObjectPublicUrlGenerator,
    ) {
    }

    public function toListResource(BankWithAccountCount $bank): BankListResource
    {
        $entity = $bank->bank;

        return new BankListResource(
            $this->requireId($entity),
            $entity->getName(),
            $entity->getShortName(),
            $entity->getCreatedAt()->format(DateTimeInterface::ATOM),
            $entity->getUpdatedAt()->format(DateTimeInterface::ATOM),
            $bank->accountCount,
        );
    }

    public function toDetailResource(BankWithAccountCount $bank): BankDetailResource
    {
        $entity = $bank->bank;

        return new BankDetailResource(
            $this->requireId($entity),
            $entity->getName(),
            $entity->getShortName(),
            $entity->getCreatedAt()->format(DateTimeInterface::ATOM),
            $entity->getUpdatedAt()->format(DateTimeInterface::ATOM),
            $this->logoUrl($entity),
            $this->storedObjectUrl($entity),
            $bank->accountCount,
        );
    }

    public function toCreateResource(Bank $bank): BankCreateResource
    {
        return new BankCreateResource(
            $this->requireId($bank),
            $bank->getName(),
            $bank->getShortName(),
            $bank->getCreatedAt()->format(DateTimeInterface::ATOM),
            $bank->getUpdatedAt()->format(DateTimeInterface::ATOM),
            $this->logoUrl($bank),
            $this->storedObjectUrl($bank),
        );
    }

    public function toUpdateResource(Bank $bank): BankUpdateResource
    {
        return new BankUpdateResource(
            $this->requireId($bank),
            $bank->getName(),
            $bank->getShortName(),
            $bank->getCreatedAt()->format(DateTimeInterface::ATOM),
            $bank->getUpdatedAt()->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * @param Page<BankWithAccountCount> $page
     *
     * @return Page<BankListResource>
     */
    public function toListPage(Page $page): Page
    {
        return new Page(
            \array_map($this->toListResource(...), $page->items),
            $page->hasNext,
            $page->hasPrev,
            $page->count,
            $page->nextCursor,
            $page->prevCursor,
        );
    }

    private function logoUrl(Bank $bank): ?string
    {
        $logo = $bank->getLogo();

        return $logo instanceof Media
            ? $this->mediaPublicUrlGenerator->urlForContentHash($logo->getContentHash())
            : null;
    }

    private function storedObjectUrl(Bank $bank): ?string
    {
        $contentHash = $bank->getStoredObject()?->contentHash;

        return null !== $contentHash
            ? $this->storedObjectPublicUrlGenerator->urlForContentHash($contentHash)
            : null;
    }

    private function requireId(Bank $bank): string
    {
        $id = $bank->getId();

        if (null === $id) {
            throw new LogicException('Cannot map a Bank without an assigned id to a resource.');
        }

        return $id;
    }
}
