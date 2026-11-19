<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Repository;

/**
 * Read port for the logo id a bank holds, for the collaborators that compose a payload from event primitives
 * instead of from a hydrated aggregate (the realtime publisher). One lookup per call, never per page.
 */
interface BankLogoReader
{
    /**
     * The id of the logo the bank holds right now, or null when it holds none OR the bank no longer exists — a late
     * `bank.updated` handled after a delete resolves null and the `bank.deleted` row follows. The answer comes from
     * the database, never from a manager's identity map.
     */
    public function logoImageIdOf(string $bankId): ?string;
}
