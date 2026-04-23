<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier\Security;

use Erpify\Tests\Behat\NodeModifier\AbstractNodeModifier;
use Override;

/**
 * Checks that a plaintext expected password matches a hashed value returned by the API,
 * using `password_verify()` — hashes are non-deterministic so direct equality would fail.
 *
 * Example (Gherkin):
 *   And the JSON node "user.passwordHash" should be equal to "<password_verify>s3cret!"
 */
class PasswordVerifyNodeModifier extends AbstractNodeModifier
{
    #[Override]
    public function getModifier(): string
    {
        return 'password_verify';
    }

    #[Override]
    public function getProcessedValue(mixed $value): mixed
    {
        return $value;
    }

    #[Override]
    public function compare(mixed $expected, mixed $value): bool
    {
        \assert(\is_scalar($expected));
        \assert(\is_scalar($value));

        return \password_verify((string) $expected, (string) $value);
    }
}
