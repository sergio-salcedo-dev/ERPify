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
     *
     * What it does NOT remove is the user, which sits before the `:` and outside the matched run — stated here
     * because a reader could otherwise take this case as proof that a connection string is scrubbed.
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
     * A long run of address bytes ending in an `@` is the shape that makes a naive pattern quadratic.
     *
     * **PCRE's JIT is disabled for the duration, and that is what gives this assertion teeth.** With the JIT on
     * — which is what this deployment and this test suite otherwise run — the engine collapses the quadratic
     * scan by itself, so the pattern measures the same with the lookbehind and without it and the bound cannot
     * fail. Measured at 200 KB: 0.507 ms against 0.135 ms with the JIT, 5 ms against 9.6 s without it. The
     * lookbehind is insurance for a deployment whose JIT is absent, so this is the configuration that can
     * observe whether the insurance is still there.
     *
     * **The shape that exposes the quadratic scan is the one where no match completes**, which is why the run
     * ends at the `@` with no domain behind it: the engine has to fail and restart at every position. Give it a
     * domain and the match succeeds at the first attempt, both variants finish in 0.1 ms, and the bound stops
     * discriminating.
     *
     * The result is asserted as well as the time, and asserting it UNCHANGED is what proves the pattern itself
     * ran: had it failed outright, the fallback would have collapsed this single `@`-bearing token to the
     * sentinel just as quickly and satisfied a bound on its own.
     */
    #[Test]
    public function itScansALongRunInLinearTimeWithNoJitToHideBehind(): void
    {
        $jit = \ini_get('pcre.jit');
        \ini_set('pcre.jit', '0');

        try {
            $hostile = \str_repeat('a.', 100_000) . '@';

            $startedAt = \hrtime(true);
            $redacted = MailAddressRedaction::apply($hostile);
            $elapsedMs = (\hrtime(true) - $startedAt) / 1e6;

            $this->assertLessThan(1_000, $elapsedMs, 'the pattern backtracks over the run instead of scanning it');
            $this->assertSame($hostile, $redacted, 'the engine failed and the fallback answered for it');
        } finally {
            \ini_set('pcre.jit', false === $jit ? '1' : $jit);
        }
    }
}
