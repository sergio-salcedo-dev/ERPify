<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Erpify\Shared\Validation\Infrastructure\PasswordPolicy;

/**
 * The bound a request body puts on a credential the identity ALREADY holds — the `currentPassword` every
 * write under `/me` demands as a re-proof of the password its caller is signed in with.
 *
 * **No password policy applies to a credential being verified, and that is the decision this states once for
 * all of them.** The value was minted under whatever rule stood when its owner set it, possibly older or
 * wider than today's, so asserting the current minimum on it would refuse exactly the people a widened rule
 * was meant to serve — the holder of a legacy password would be locked out of the endpoint that replaces it,
 * and out of the recovery credential that is their way back in. {@see PasswordPolicy} belongs on the
 * credential being CREATED and nowhere else.
 *
 * What is left is a coarse ceiling, deliberately far above every credential this system can hold rather than
 * at any meaningful length. Its only job is to stop an oversized body turning one KDF run into an
 * amplification lever, which is a transport property of the request and not a rule about passwords.
 *
 * It is one constant because the bound belongs to the credential rather than to any endpoint that asks for
 * it: a per-DTO copy is a number three files can drift apart on, silently and in the direction that widens.
 */
final class ExistingCredential
{
    public const int LENGTH_CEILING = 255;

    /**
     * A namespace for one bound, never a value.
     */
    private function __construct()
    {
    }
}
