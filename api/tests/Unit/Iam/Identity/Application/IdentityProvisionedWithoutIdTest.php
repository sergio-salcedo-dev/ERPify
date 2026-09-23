<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\IdentityProvisionedWithoutId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(IdentityProvisionedWithoutId::class)]
final class IdentityProvisionedWithoutIdTest extends TestCase
{
    /**
     * The guard this names is unreachable while the application layer keeps minting the id before the
     * save, so nothing on the live path executes it — this is its only witness.
     *
     * Whether the class sits on the programming-error half of the SPL hierarchy is deliberately NOT
     * asserted here. Its factory's return type proves that statically, so every spelling of the
     * assertion — `assertInstanceOf`, `get_parent_class` — is one PHPStan folds to true at `level: max`
     * and reports as `method.alreadyNarrowedType`. Both measured. The remaining ways to write it are to
     * obscure the class reference until the analyser loses it, or to silence the analyser; each is worse
     * than declining to make a claim no run can check. The argument for the base class is in its
     * docblock, where review reads it.
     *
     * The message reaches an operator's console and, through the buffered handler, the container log.
     * It states the invariant and names no person: neither the email the command was given nor the id
     * that is missing.
     */
    #[Test]
    public function itsMessageNamesTheInvariantAndNoPerson(): void
    {
        $message = IdentityProvisionedWithoutId::afterCreation()->getMessage();

        $this->assertStringContainsString('provisioned without an id', $message);
        $this->assertStringNotContainsString('@', $message);
    }
}
