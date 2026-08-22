<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A MIME message is built in one place under `api/src`, and that place also sends it.
 *
 * **The reason is positional and no amount of care replaces it.** `Mime\Address` refuses a non-compliant value
 * with a message quoting it, and that throw happens while a message is being assembled — upstream of any
 * transport decorator, where the best-effort wrapper around every mail path logs the throwable raw onto a sink
 * with a size bound but no TTL and no owner of erasure. A sender that assembles its own `Email` therefore leaks the
 * address it was handed, and it does so with every other gate in this repository green.
 *
 * **So the property gated here is a source fact, not a behaviour.** `RedactingMailerTest` proves the
 * translation is right; nothing there notices a second construction site opening somewhere else in the tree,
 * because a new sender that builds its own message satisfies its own unit test perfectly. The two are
 * complementary and neither implies the other.
 *
 * The mailer component is pinned alongside the message namespaces because holding a mailer or a transport is
 * the other half of the door: a class that can reach either directly can send a message this boundary never
 * assembled.
 *
 * **Each set is a list of namespace PREFIXES rather than of the exact types in use, because the exact types
 * were measured to be evadable one `use` statement at a time.** `Symfony\Bridge\Twig\Mime\TemplatedEmail` and
 * its `NotificationEmail` sibling are installed, extend `Email`, and refuse a malformed recipient with the same
 * address-quoting exception — the shape a future HTML-template mail path reaches for first, and it shares no
 * token with the component namespace. On the other side `Symfony\Component\Mailer\Mailer` and
 * `Symfony\Component\Mailer\Transport\TransportInterface` both miss an interface-exact token, and the transport
 * is the id three consumers in the compiled container take directly. Pinning the prefixes is what makes the
 * four of them arrive as an edit here rather than as silence.
 *
 * What a green proves: no file under `api/src` other than the pinned ones names a message namespace, and none
 * other names the mailer component.
 *
 * What it does not prove:
 * - **Nothing about vendor code, which is most of what remains.** `MailerTestCommand` builds its own message
 *   from an operator's argument, upstream of everything pinned here; measured,
 *   `bin/console mailer:test alice-the-victim` writes that value five times, across four fields of one
 *   `console.CRITICAL` record. The notifier's email channel builds its own message too. Neither is reachable
 *   from this sweep, and the console command is carried as a residual in `PRODUCTION_SECURITY_CHECKLIST.md`.
 * - **Assembly is not the only throw the boundary misses.** `Mailer::send()` dispatches a `MessageEvent` of
 *   its own before handing the message to the bus — between `RedactingMailer`'s assembly `try` and
 *   `RedactingTransport`'s send `try` — so a subscriber that mutates the message there and is refused raises
 *   outside both. Measured on the compiled container: `mailer.mailer` is built with `messenger.default_bus`
 *   and `event_dispatcher`, so that dispatch happens. No subscriber in this tree touches a message; a future
 *   one is invisible both to this gate and to the translation.
 * - It is a text match over source. A type reached through a computed class-string, a class alias or a
 *   container id is invisible to it.
 * - It says nothing about whether the assembly is actually translated, only about where it happens.
 * - It matches prose as well as code, so a docblock elsewhere spelling one of these namespaces reds it. That is
 *   the accepted cost of a match cheap enough to have no configuration of its own.
 *
 * @internal
 */
#[CoversNothing]
final class MailAssemblyBoundaryGateTest extends TestCase
{
    /**
     * Every namespace holding a type that parses an address while a message is being built. The Twig bridge is
     * a separate root and would be reached by a first templated mail path, which is why it is named rather
     * than assumed to be covered by the component.
     *
     * @var list<string>
     */
    private const array MESSAGE_NAMESPACES = [
        'Symfony\Bridge\Twig\Mime',
        'Symfony\Component\Mime',
    ];

    /**
     * The whole mailer component, not `MailerInterface`: the concrete `Mailer`, the transport interface and
     * every transport factory sit under it, and a class reaching any of them can put a message on the wire.
     *
     * @var list<string>
     */
    private const array MAILER_NAMESPACES = [
        'Symfony\Component\Mailer',
    ];

    /**
     * `RedactingMailer` assembles; `RedactingTransport` takes the message the mailer hands it and never builds
     * one. Adding a third is a deliberate edit here, and that is the whole mechanism: a count would tell a new
     * construction site from nothing.
     *
     * @var list<string>
     */
    private const array MESSAGE_SITES = [
        'Shared/Mailer/Infrastructure/RedactingMailer.php',
        'Shared/Mailer/Infrastructure/RedactingTransport.php',
    ];

    /**
     * Wider than the assembly set by exactly the two files that speak the component without building anything:
     * the translated failure implements the component's own exception interface, and the SMTP factory exists to
     * put a read and a write timeout on the socket.
     *
     * @var list<string>
     */
    private const array MAILER_SITES = [
        'Shared/Mailer/Infrastructure/MailDeliveryFailed.php',
        'Shared/Mailer/Infrastructure/RedactingMailer.php',
        'Shared/Mailer/Infrastructure/RedactingTransport.php',
        'Shared/Mailer/Infrastructure/TimeBoundedSmtpTransportFactory.php',
    ];

    #[Test]
    public function onlyTheBoundaryBuildsAMimeMessage(): void
    {
        $this->assertSame(
            self::MESSAGE_SITES,
            $this->filesNamingAny(self::MESSAGE_NAMESPACES),
            'a MIME message is constructed outside the mail boundary, where a refused address is quoted into '
            . 'a throwable nothing translates',
        );
    }

    #[Test]
    public function onlyTheBoundaryHoldsTheMailerOrItsTransport(): void
    {
        $this->assertSame(
            self::MAILER_SITES,
            $this->filesNamingAny(self::MAILER_NAMESPACES),
            'a class other than the boundary can reach the mailer or its transport, so it can send a message '
            . 'the boundary never assembled',
        );
    }

    /**
     * @param list<string> $tokens
     *
     * @return list<string>
     */
    private function filesNamingAny(array $tokens): array
    {
        $root = ApiSourceFiles::root();
        $naming = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $source = \file_get_contents($file->getPathname());
            $this->assertIsString($source, $file->getPathname() . ' could not be read, so it was never swept');

            foreach ($tokens as $token) {
                if (\str_contains($source, $token)) {
                    $naming[] = \substr($file->getPathname(), \strlen($root) + 1);

                    break;
                }
            }
        }

        \sort($naming);

        return $naming;
    }
}
