<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Mailer\Application;

use Erpify\Shared\Mailer\Application\MailAddressRedaction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(MailAddressRedaction::class)]
final class MailAddressRedactionTest extends TestCase
{
    /**
     * The forms are the ones `#[Assert\Email(mode: STRICT)]` admits, spelled as `Email::from()` canonicalises
     * them — NFC and lower-cased — because that is the shape an address has by the time it can appear in a
     * server's reply to `RCPT TO:`. A narrow, RFC-shaped pattern matches none of the first four.
     */
    #[Test]
    #[DataProvider('provideItRemovesAnAddressTheApplicationCanSendCases')]
    public function itRemovesAnAddressTheApplicationCanSend(string $address): void
    {
        $line = \sprintf('550 5.1.1 <%s>: Recipient address rejected: User unknown', $address);

        $redacted = MailAddressRedaction::apply($line);

        $this->assertStringNotContainsString($address, $redacted);
        $this->assertStringContainsString(MailAddressRedaction::SENTINEL, $redacted);
        // The diagnosis outlives the redaction, which is why this replaces rather than strips.
        $this->assertStringContainsString('550 5.1.1', $redacted);
        $this->assertStringContainsString('Recipient address rejected', $redacted);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideItRemovesAnAddressTheApplicationCanSendCases(): iterable
    {
        yield 'non-ASCII local part' => ['josé@example.test'];
        yield 'internationalised domain' => ['alice@exämple.test'];
        yield 'braces in the local part' => ['a{b}@example.test'];
        yield 'single-character TLD' => ['alice@example.c'];
        yield 'plain ASCII address' => ['alice@example.test'];
    }

    #[Test]
    #[DataProvider('provideItKeepsTheDelimitersAroundAnAddressCases')]
    public function itKeepsTheDelimitersAroundAnAddress(string $line, string $expected): void
    {
        // An operator reads the shape of a reply, so the punctuation that separates one address from the next
        // has to survive: a rule that swallowed the brackets would also fuse two recipients into one token.
        $this->assertSame($expected, MailAddressRedaction::apply($line));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideItKeepsTheDelimitersAroundAnAddressCases(): iterable
    {
        yield 'trailing punctuation' => ['<a@b.test>,', '<REDACTED>,'];
        yield 'two addresses' => ['<a@b.test>,<c@d.test>', '<REDACTED>,<REDACTED>'];
        yield 'a reply naming three' => [
            '450 4.1.2 <a@b.test>: rejected; from=<c@d.test> to=<e@f.test>',
            '450 4.1.2 <REDACTED>: rejected; from=<REDACTED> to=<REDACTED>',
        ];
    }

    #[Test]
    #[DataProvider('provideItRewritesNothingWithoutAnAddressCases')]
    public function itRewritesNothingWithoutAnAddress(string $message): void
    {
        // A redactor that rewrites ordinary prose costs the operator the diagnosis and buys no privacy.
        $this->assertSame($message, MailAddressRedaction::apply($message));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideItRewritesNothingWithoutAnAddressCases(): iterable
    {
        yield 'no at sign' => ['Connection could not be established with host smtp:1025'];
        yield 'at sign standing alone' => ['Rate limit hit @ retry later'];
    }

    /**
     * Stated rather than silently missed, and asserted so the statement cannot quietly stop being true: both
     * forms are refused by the strict `#[Assert\Email]` mode the aggregate carries, so neither can be an
     * address this application sent to a server.
     */
    #[Test]
    public function itLeavesTheTwoFormsTheApplicationCannotSend(): void
    {
        $this->assertSame(
            'RCPT TO:<alice@[192.168.1.10]> refused',
            MailAddressRedaction::apply('RCPT TO:<alice@[192.168.1.10]> refused'),
        );
        $this->assertSame(
            '<"REDACTED"@example.test>',
            MailAddressRedaction::apply('<"a@b"@example.test>'),
        );
    }

    /**
     * The cost of drawing the boundary at the RFC's `specials` rather than at a URI grammar, asserted so the
     * cost is a decision rather than a surprise: `@` separates credentials from host in a connection string
     * too, so the host is swallowed along with the password and only the port survives. Over-redacting a log
     * costs a diagnostic; under-redacting it costs an identifier that outlives its own erasure.
     */
    #[Test]
    public function itSwallowsTheHostOfAConnectionStringAlongWithTheCredentials(): void
    {
        $this->assertSame(
            'postgresql://app:REDACTED:5432/erpify?sslmode=require',
            MailAddressRedaction::apply('postgresql://app:pw@db.internal:5432/erpify?sslmode=require'),
        );
    }

    /**
     * A long run of address bytes ending in an `@` is the shape that makes a naive pattern quadratic. The
     * assertion is a wall-clock bound rather than an exact figure: it fails on a pattern that re-splits the
     * run, and passes on any machine for one that does not.
     */
    #[Test]
    public function itScansALongRunInLinearTime(): void
    {
        $hostile = \str_repeat('a.', 100_000) . '@';

        $startedAt = \hrtime(true);
        MailAddressRedaction::apply($hostile);
        $elapsedMs = (\hrtime(true) - $startedAt) / 1e6;

        $this->assertLessThan(1_000, $elapsedMs, 'the pattern backtracks over the run instead of scanning it');
    }
}
