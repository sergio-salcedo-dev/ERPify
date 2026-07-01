<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Application;

/**
 * A data-encryption key already sealed under the key-encryption key, as it lives in the keystore: the
 * raw wrapped bytes plus the KEK version they were wrapped under (so rotation can rewrap them). The
 * plaintext DEK never appears here.
 */
final readonly class WrappedDek
{
    public function __construct(
        public string $bytes,
        public int $kekVersion,
    ) {
    }
}
