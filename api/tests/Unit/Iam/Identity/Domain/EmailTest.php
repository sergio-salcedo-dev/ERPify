<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain;

use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Exception\InvalidEmail;
use Normalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Email::class)]
final class EmailTest extends TestCase
{
    #[DataProvider('provideCanonicalisesTheValueCases')]
    public function testCanonicalisesTheValue(string $raw, string $expected): void
    {
        $this->assertSame($expected, Email::from($raw)->toString());
    }

    /**
     * The canonical value, case by case. A provider rather than a method each because PHPUnit names the failing
     * data set, so a broken rule still goes red on its own line — and the class stays under the public-method
     * ceiling without any rule losing its own red.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideCanonicalisesTheValueCases(): iterable
    {
        yield 'ascii padding and case' => ['  Alice@ERPify.TEST  ', 'alice@erpify.test'];
        // `trim()` strips " \t\n\r\0\x0B" and nothing else, so a NO-BREAK SPACE survives it — and an address
        // pasted from a rich-text client routinely carries one. Uncanonicalised it misses `findByEmail`, and a
        // legitimate account answers 401.
        yield 'no-break space' => ["\u{00A0}alice@erpify.test\u{00A0}", 'alice@erpify.test'];
        // U+FEFF is category `Cf`, NOT `Zs`, so the obvious `\p{Z}` class misses it — and it is the likeliest
        // character to lead a pasted value, being what a UTF-8 BOM decodes to.
        yield 'byte-order mark' => ["\u{FEFF}alice@erpify.test", 'alice@erpify.test'];
        // The tempting reading of "strip Unicode whitespace" is a global sweep, and it is a defect: a quoted
        // local part is RFC-valid and the aggregate validates in strict mode, so sweeping the interior would
        // canonicalise two distinct mailboxes onto one value over a column declared unique.
        yield 'quoted local part keeps its space' => ['  "john doe"@erpify.test  ', '"john doe"@erpify.test'];
    }

    public function testRejectsABlankValue(): void
    {
        $this->expectException(InvalidEmail::class);

        Email::from('   ');
    }

    public function testTryFromYieldsNullForABlankValueAndTheCanonicalEmailOtherwise(): void
    {
        $this->assertNotInstanceOf(Email::class, Email::tryFrom('   '));
        $this->assertSame('alice@erpify.test', Email::tryFrom('  Alice@ERPify.TEST  ')?->toString());
    }

    public function testEqualityIsByCanonicalValue(): void
    {
        $email = Email::from('bob@erpify.test');

        $this->assertTrue($email->equals(Email::from('BOB@erpify.test')));
        $this->assertFalse($email->equals(Email::from('carol@erpify.test')));
    }

    public function testTwoUnicodeSpellingsOfOneAddressAreOneIdentity(): void
    {
        // The composed and decomposed spellings of `josé` are the same address to every human and to every
        // mail server. Without NFC they are two rows on a UNIQUE column — or, more likely, one row and one
        // caller who can never log in.
        $composed = Email::from("jos\u{00E9}@erpify.test");
        $decomposed = Email::from("jose\u{0301}@erpify.test");

        $this->assertTrue($composed->equals($decomposed));
        $this->assertSame($composed->toString(), $decomposed->toString());
    }

    public function testRejectsBytesThatAreNotValidUtf8(): void
    {
        // `Normalizer::normalize()` answers `false` rather than throwing on malformed UTF-8. Left unchecked
        // that `false` would flow into the canonical value as `''` and the VO's own blank guard would report
        // the wrong reason. Bytes that are not text cannot be an address: the uniform refusal is correct.
        $this->expectException(InvalidEmail::class);

        Email::from("\xC3\x28@erpify.test");
    }

    public function testTwoDistinctQuotedMailboxesStayDistinct(): void
    {
        // The failure the test above prevents, stated as the collision it would produce rather than as the
        // value it would return: these are two mailboxes and a unique column must keep them apart.
        $this->assertFalse(
            Email::from('"john doe"@erpify.test')->equals(Email::from('"johndoe"@erpify.test')),
        );
    }

    public function testTheCanonicalFormIsNormalisedAndIsAFixedPoint(): void
    {
        // The property, asserted directly instead of inferred from the order of the canonicaliser's steps:
        // whatever the input, the output is NFC and canonicalising it again changes nothing. A future
        // simplification that reorders those steps is free to do so — as long as this still holds.
        foreach (["JOSE\u{0301}@erpify.test", "\u{FEFF}  Ünïcode@ERPify.TEST \u{00A0}"] as $raw) {
            $once = Email::from($raw)->toString();

            $this->assertTrue(Normalizer::isNormalized($once, Normalizer::FORM_C), 'not NFC: ' . $once);
            $this->assertSame($once, Email::from($once)->toString(), 'not a fixed point: ' . $once);
        }
    }
}
