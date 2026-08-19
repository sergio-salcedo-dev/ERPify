<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A MIME message is built in one place, and that place also sends it.
 *
 * **The reason is positional and no amount of care replaces it.** `Mime\Address` refuses a non-compliant value
 * with a message quoting it, and that throw happens while a message is being assembled — upstream of any
 * transport decorator, where the best-effort wrapper around every mail path logs the throwable raw onto a sink
 * with no rotation, no TTL and no owner of erasure. A sender that assembles its own `Email` therefore leaks the
 * address it was handed, and it does so with every other gate in this repository green.
 *
 * **So the property gated here is a source fact, not a behaviour.** `RedactingMailerTest` proves the
 * translation is right; nothing there notices a second construction site opening somewhere else in the tree,
 * because a new sender that builds its own message satisfies its own unit test perfectly. The two are
 * complementary and neither implies the other.
 *
 * The mailer is pinned alongside the MIME namespace because holding a `MailerInterface` is the other half of
 * the door: a class that can reach the mailer directly can send a message this boundary never assembled.
 *
 * What a green proves: no file under `api/src` other than the pinned ones names the MIME component, and none
 * other names the mailer.
 *
 * What it does not prove:
 * - It is a text match over source. A type reached through a computed class-string, a class alias or a
 *   container id is invisible to it, as is anything vendor code constructs on its own behalf (the notifier's
 *   email channel builds its own message and is not wired here).
 * - It says nothing about whether the assembly is actually translated, only about where it happens.
 * - It matches prose as well as code, so a docblock elsewhere spelling the full namespace reds it. That is the
 *   accepted cost of a match cheap enough to have no configuration of its own.
 *
 * @internal
 */
#[CoversNothing]
final class MailAssemblyBoundaryGateTest extends TestCase
{
    private const string MIME_COMPONENT = 'Symfony\Component\Mime';

    private const string MAILER = \Symfony\Component\Mailer\MailerInterface::class;

    /**
     * `RedactingMailer` assembles; `RedactingTransport` takes the `RawMessage` the mailer hands it and never
     * builds one. Adding a third is a deliberate edit here, and that is the whole mechanism: a count would
     * tell a new construction site from nothing.
     *
     * @var list<string>
     */
    private const array MIME_SITES = [
        'Shared/Mailer/Infrastructure/RedactingMailer.php',
        'Shared/Mailer/Infrastructure/RedactingTransport.php',
    ];

    /** @var list<string> */
    private const array MAILER_SITES = [
        'Shared/Mailer/Infrastructure/RedactingMailer.php',
    ];

    #[Test]
    public function onlyTheBoundaryBuildsAMimeMessage(): void
    {
        $this->assertSame(
            self::MIME_SITES,
            $this->filesNaming(self::MIME_COMPONENT),
            'a MIME message is constructed outside the mail boundary, where a refused address is quoted into '
            . 'a throwable nothing translates',
        );
    }

    #[Test]
    public function onlyTheBoundaryHoldsTheMailer(): void
    {
        $this->assertSame(
            self::MAILER_SITES,
            $this->filesNaming(self::MAILER),
            'a class other than the boundary can reach the mailer, so it can send a message the boundary '
            . 'never assembled',
        );
    }

    /**
     * @return list<string>
     */
    private function filesNaming(string $token): array
    {
        $root = ApiSourceFiles::root();
        $naming = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $source = \file_get_contents($file->getPathname());
            $this->assertIsString($source, $file->getPathname() . ' could not be read, so it was never swept');

            if (\str_contains($source, $token)) {
                $naming[] = \substr($file->getPathname(), \strlen($root) + 1);
            }
        }

        \sort($naming);

        return $naming;
    }
}
