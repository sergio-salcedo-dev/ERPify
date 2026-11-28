<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use SensitiveParameter;

/**
 * A freshly started enrolment: the shared secret in the two forms an authenticator accepts, for the one response
 * that will ever carry it. Neither value is persisted or logged — only the ciphertext reaches the row — and no
 * read route returns them, so a lost response means starting the enrolment again.
 *
 * Both values are PRIVATE fields read through accessors, mirroring
 * {@see \Erpify\Iam\Identity\Domain\Entity\GeneratedRecoverySecret}: an accidental `json_encode` or log of the
 * whole object emits no public state, and each raw read is greppable.
 */
final readonly class TotpEnrolment
{
    public function __construct(
        #[SensitiveParameter]
        private string $encodedSecret,
        #[SensitiveParameter]
        private string $provisioningUri,
    ) {
    }

    public function encodedSecret(): string
    {
        return $this->encodedSecret;
    }

    public function provisioningUri(): string
    {
        return $this->provisioningUri;
    }
}
