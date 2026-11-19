<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Repository\BankLogoReader;
use Override;

/**
 * In-memory {@see BankLogoReader}: a `bankId => logo id` map. A bank absent from it holds no logo, which is also what
 * the port answers for a bank that no longer exists, and every call is recorded so a test can assert one lookup per
 * event.
 *
 * @internal
 */
final class InMemoryBankLogoReader implements BankLogoReader
{
    /** @var list<string> the ids asked about, in order */
    public array $asked = [];

    /**
     * @param array<string, string> $logos
     */
    public function __construct(private array $logos = [])
    {
    }

    public function setLogo(string $bankId, ?string $logoImageId): void
    {
        if (null === $logoImageId) {
            unset($this->logos[$bankId]);

            return;
        }

        $this->logos[$bankId] = $logoImageId;
    }

    #[Override]
    public function logoImageIdOf(string $bankId): ?string
    {
        $this->asked[] = $bankId;

        return $this->logos[$bankId] ?? null;
    }
}
