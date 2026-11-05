<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Domain;

/**
 * What the keystore holds for a scope. The three are distinct on purpose: a key lookup cannot tell a
 * tombstone (the key was destroyed) from absence (no key was ever minted), and the difference decides
 * whether sealing under the scope is refused for ever or still possible.
 */
enum KeyState
{
    case Live;
    case Tombstoned;
    case Absent;
}
