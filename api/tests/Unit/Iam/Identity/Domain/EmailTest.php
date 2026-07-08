<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain;

use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Exception\InvalidEmail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Email::class)]
final class EmailTest extends TestCase
{
    public function testCanonicalisesToTrimmedLowercase(): void
    {
        $this->assertSame('alice@erpify.test', Email::from('  Alice@ERPify.TEST  ')->toString());
    }

    public function testRejectsABlankValue(): void
    {
        $this->expectException(InvalidEmail::class);

        Email::from('   ');
    }

    public function testEqualityIsByCanonicalValue(): void
    {
        $email = Email::from('bob@erpify.test');

        $this->assertTrue($email->equals(Email::from('BOB@erpify.test')));
        $this->assertFalse($email->equals(Email::from('carol@erpify.test')));
    }
}
