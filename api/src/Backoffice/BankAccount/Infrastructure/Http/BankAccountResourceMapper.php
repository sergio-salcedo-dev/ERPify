<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Http;

use DateTimeInterface;
use Erpify\Backoffice\BankAccount\Application\Resource\BankAccountCollectionResource;
use Erpify\Backoffice\BankAccount\Application\Resource\BankAccountListResource;
use Erpify\Backoffice\BankAccount\Application\Resource\BankAccountResource;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Projection\BankAccountCollectionRow;
use Erpify\Shared\Search\Domain\Page;
use LogicException;

/**
 * Maps the read-side sources of an account to the resource DTO that is serialized. Single place that
 * knows the account wire shapes: the single-account views (create / detail / update) and the nested
 * list view (`GET /banks/{id}/accounts`, which drops the owning `bankId` since it is the route) map
 * from the {@see BankAccount} aggregate, while the cross-bank collection view (`GET /bank-accounts`)
 * maps from the {@see BankAccountCollectionRow} projection and carries the owning bank's identity
 * (`bankId` / `bankName` / `bankShortName`). In every shape `currency` / `status` are resolved to their
 * enum identity value and `bic` / `alias` flow through as nullable. Neither the BankAccount entity nor
 * the projection row reaches the serializer.
 */
final readonly class BankAccountResourceMapper
{
    public function toResource(BankAccount $account): BankAccountResource
    {
        return new BankAccountResource(
            $this->requireId($account),
            $account->getBankId(),
            $account->getHolderName(),
            $account->getIban(),
            $account->getBic(),
            $account->getAlias(),
            $account->getCurrency()->value,
            $account->getStatus()->value,
            $account->getCreatedAt()->format(DateTimeInterface::ATOM),
            $account->getUpdatedAt()->format(DateTimeInterface::ATOM),
        );
    }

    public function toListResource(BankAccount $account): BankAccountListResource
    {
        return new BankAccountListResource(
            $this->requireId($account),
            $account->getHolderName(),
            $account->getIban(),
            $account->getBic(),
            $account->getAlias(),
            $account->getCurrency()->value,
            $account->getStatus()->value,
        );
    }

    /**
     * @param Page<BankAccount> $page
     *
     * @return Page<BankAccountListResource>
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

    public function toCollectionResource(BankAccountCollectionRow $row): BankAccountCollectionResource
    {
        return new BankAccountCollectionResource(
            $row->id,
            $row->bankId,
            $row->bankName,
            $row->bankShortName,
            $row->holderName,
            $row->iban,
            $row->bic,
            $row->alias,
            $row->currency->value,
            $row->status->value,
        );
    }

    /**
     * @param Page<BankAccountCollectionRow> $page
     *
     * @return Page<BankAccountCollectionResource>
     */
    public function toCollectionPage(Page $page): Page
    {
        return new Page(
            \array_map($this->toCollectionResource(...), $page->items),
            $page->hasNext,
            $page->hasPrev,
            $page->count,
            $page->nextCursor,
            $page->prevCursor,
        );
    }

    private function requireId(BankAccount $account): string
    {
        $id = $account->getId();

        if (null === $id) {
            throw new LogicException('Cannot map a BankAccount without an assigned id to a resource.');
        }

        return $id;
    }
}
