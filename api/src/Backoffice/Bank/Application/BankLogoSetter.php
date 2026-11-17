<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Shared\Images\Application\ImageAttachment;
use Erpify\Shared\Images\Domain\Exception\ImageProcessingException;
use Erpify\Shared\Images\Domain\ImageId;
use SensitiveParameter;

/**
 * Sets or replaces a bank's logo. The caller has already authenticated, authorised and found the bank, so the body
 * is read only for a bank that exists; what remains is the protocol of {@see ImageAttachment}, which stores the
 * bytes, attaches the new id under the bank's row lock and queues the release of the one it replaces.
 */
final readonly class BankLogoSetter
{
    public function __construct(
        private ImageAttachment $imageAttachment,
        private BankLogoHolder $bankLogoHolder,
    ) {
    }

    /**
     * @throws BankNotFoundException    when the bank is gone by the time the lock is taken (the new image is then
     *                                  released)
     * @throws ImageProcessingException when the bytes are not an acceptable image
     */
    public function set(string $bankId, #[SensitiveParameter] string $bytes, ?string $declaredMediaType): ImageId
    {
        return $this->imageAttachment->replace($this->bankLogoHolder, $bankId, $bytes, $declaredMediaType);
    }
}
