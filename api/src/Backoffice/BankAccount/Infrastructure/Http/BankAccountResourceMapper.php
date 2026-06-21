<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Http;

use Erpify\Backoffice\BankAccount\Application\Resource\BankAccountListResource;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Shared\Search\Domain\Page;
use LogicException;

/**
 * Maps a {@see BankAccount} aggregate to the list-view resource DTO that is serialized for
 * `GET /banks/{id}/accounts`. Single place that knows the account wire shape: the owning `bankId` is
 * intentionally dropped (it is the route), `currency` / `status` are resolved to their enum identity
 * value, and `bic` / `alias` flow through as nullable. The BankAccount entity never reaches the
 * serializer.
 */
final readonly class BankAccountResourceMapper
{
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

    private function requireId(BankAccount $account): string
    {
        $id = $account->getId();

        if (null === $id) {
            throw new LogicException('Cannot map a BankAccount without an assigned id to a resource.');
        }

        return $id;
    }
}
