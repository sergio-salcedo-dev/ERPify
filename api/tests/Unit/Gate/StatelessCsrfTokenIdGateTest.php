<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * Every CSRF token id a controller declares must be registered as STATELESS, and every registered id must be
 * declared by a controller.
 *
 * **The failure this refuses is total, not partial, and no other check in the tree can see it.** An id absent
 * from `csrf_protection.stateless_token_ids` falls through to the session-backed token manager. Every surface
 * carrying `#[IsCsrfTokenValid]` here is PRE-IDENTITY by construction — invitation acceptance, password reset,
 * recovery-secret redemption — so their callers hold no session and cannot satisfy that manager at all: the
 * endpoint answers 401 to every legitimate request, not merely to a forged one. Measured on the running stack
 * while `recovery_redeem` was unregistered: a well-formed token got 401 there and the intended opaque 400 on
 * `password_reset`, from requests identical in every other respect. The attribute compiles, the route
 * registers, the access-control exemption is correct, PHPStan and deptrac are green, and the controller is
 * simply never reached — the first reader would have been whoever tried to recover an account.
 *
 * The check is an EQUALITY between the two sets rather than one containment, because both directions are
 * defects: an unregistered id breaks its endpoint, and a registered id no controller declares is either a
 * deleted surface leaving a widened configuration behind or a typo that is silently protecting nothing.
 *
 * **The rule holds because every CSRF-protected route in this tree is pre-identity, and that is a property of
 * today's tree rather than a law.** An authenticated route wanting a session-backed token would be a
 * legitimate counter-example, and the answer then is to give this gate a classification — the registry shape
 * the repository uses for exactly this — never to suppress it or to weaken the equality to a containment.
 *
 * It reads the ids as the quoted literal beside `CSRF_TOKEN_ID`, so a controller assembling its id at runtime
 * is invisible here; nothing does that today, and a green says nothing about one that starts.
 *
 * @internal test support
 */
#[CoversNothing]
final class StatelessCsrfTokenIdGateTest extends TestCase
{
    #[Test]
    public function everyDeclaredCsrfTokenIdIsRegisteredAsStatelessAndViceVersa(): void
    {
        $declared = $this->declaredTokenIds();
        $registered = $this->registeredStatelessTokenIds();

        // Neither side may be empty, or the equality below is a comparison of two nothings that passes
        // whatever happens to the tree — the shape in which this gate would quietly stop being a control.
        $this->assertNotEmpty($declared, 'no #[IsCsrfTokenValid] token id was found; the gate has no subject');
        $this->assertNotEmpty($registered, 'config/packages/csrf.yaml declares no stateless token id');

        $this->assertSame(
            $registered,
            $declared,
            "The CSRF token ids controllers declare and the ids registered as stateless have diverged.\n"
            . "An UNREGISTERED id falls to the session-backed manager, which the anonymous caller these\n"
            . "routes exist for cannot satisfy — the endpoint then refuses every legitimate request with a\n"
            . '401. A REGISTERED id nobody declares is a widened configuration outliving its surface.',
        );
    }

    /**
     * @return list<string>
     */
    private function declaredTokenIds(): array
    {
        $ids = [];

        foreach ($this->controllersDeclaringACsrfToken() as $source) {
            if (1 === \preg_match("/public const string CSRF_TOKEN_ID = '([^']+)'/", $source, $matches)) {
                $ids[] = $matches[1];
            }
        }

        \sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function controllersDeclaringACsrfToken(): array
    {
        $sources = [];
        $root = \dirname(__DIR__, 3) . '/src';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $source = \file_get_contents($file->getPathname());

            if (\is_string($source) && \str_contains($source, '#[IsCsrfTokenValid(')) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /**
     * @return list<string>
     */
    private function registeredStatelessTokenIds(): array
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/packages/csrf.yaml');
        // Walked one key at a time rather than chained: a chained access asserts the shape of a file this
        // gate exists to read as untrusted data, and a restructured YAML should red the assertion above with
        // an empty set rather than fatal here with a type error.
        $framework = \is_array($config) ? ($config['framework'] ?? null) : null;
        $protection = \is_array($framework) ? ($framework['csrf_protection'] ?? null) : null;
        $declared = \is_array($protection) ? ($protection['stateless_token_ids'] ?? null) : null;

        $ids = \is_array($declared) ? \array_values(\array_filter($declared, \is_string(...))) : [];

        \sort($ids);

        return $ids;
    }
}
