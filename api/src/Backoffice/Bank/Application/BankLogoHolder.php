<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Images\Application\ImageHolder;
use Erpify\Shared\Images\Domain\ImageId;
use Override;

/**
 * A bank as the owner of one logo, for {@see \Erpify\Shared\Images\Application\ImageAttachment}. The bank row is the
 * lock of the whole protocol: it is taken first and held to the end of the attach unit, so two writers on one bank
 * take it in turn and each replaced id is released once. Stateless, so one instance serves every request of a
 * long-lived worker.
 */
final readonly class BankLogoHolder implements ImageHolder
{
    public function __construct(private BankRepository $bankRepository, private BankFinder $bankFinder)
    {
    }

    #[Override]
    public function lockAndRead(string $ownerId): ?ImageId
    {
        $logoImageId = $this->bankFinder->findForUpdate($ownerId)->getLogoImageId();

        return null === $logoImageId ? null : ImageId::fromString($logoImageId);
    }

    /**
     * Reads the bank {@see lockAndRead()} just loaded and locked, from the identity map it was refreshed into, so
     * the id replaced is the id the lock froze.
     */
    #[Override]
    public function attach(string $ownerId, ?ImageId $imageId): void
    {
        $bank = $this->bankFinder->find($ownerId);

        $bank->changeLogo($imageId?->toString());

        $this->bankRepository->save($bank);
    }
}
