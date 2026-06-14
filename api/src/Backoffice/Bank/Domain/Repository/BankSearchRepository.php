<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Repository;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Domain\Search\Page;
use Erpify\Shared\Domain\Search\SearchCriteria;

/**
 * Read-side search port — the swappable surface. Implementations may be backed
 * by the system of record (Doctrine) or by a projection (e.g. Elasticsearch);
 * aggregate writes stay on {@see BankRepository}.
 *
 * PR3: returns the keyset {@see Page} (cursor-only navigation) — the engine's
 * domain artifact, with OPAQUE next/prev cursors. The port is link-agnostic; the
 * HTTP envelope (relative `links`) is assembled by the responder, never here.
 */
interface BankSearchRepository
{
    /** @return Page<Bank> */
    public function search(SearchCriteria $criteria): Page;
}
