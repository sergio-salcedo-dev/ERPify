<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\NodeModifier\Security;

use Erpify\Tests\Behat\NodeModifier\Security\PasswordVerifyNodeModifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PasswordVerifyNodeModifier::class)]
final class PasswordVerifyNodeModifierTest extends TestCase
{
    #[Test]
    public function itVerifiesThePlaintextAgainstAHash(): void
    {
        $modifier = new PasswordVerifyNodeModifier();
        $hash = \password_hash('s3cret!', PASSWORD_DEFAULT);

        $this->assertTrue($modifier->compare('s3cret!', $hash));
        $this->assertFalse($modifier->compare('wrong', $hash));
    }

    #[Test]
    public function itFailsForANonScalarSideInsteadOfAborting(): void
    {
        $modifier = new PasswordVerifyNodeModifier();

        $this->assertFalse($modifier->compare('s3cret!', []));
        $this->assertFalse($modifier->compare([], 'irrelevant'));
    }
}
